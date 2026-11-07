<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Models\Competitor;
use RuntimeException;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Intelligence rules under truly parallel requests and with committed data (TASK-0084, moved into P1-41):
 * - claim moves and evidence links on one item, all from the same version: the item's row lock and version check let
 *   exactly one through, and positions stay a permutation;
 * - creating an item about a competitor and archiving that competitor are ordered by the competitor's row (share lock
 *   versus FOR UPDATE), whichever comes first: never an open item about an archived competitor;
 * - a decision waiting on the item's lock while a director reassigns the review is refused (P1-43);
 * - the Intelligence migrations refuse to roll back while items, links or reviews exist, cited evidence cannot be
 *   removed, and all roll back and re-apply once empty (the reviews migration ages items already waiting).
 * DatabaseMigrations, so tearDown removes the rows (the app account cannot, and the migrations refuse while they exist).
 */
class IntelligenceRaceTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const ROUNDS = 3;

    private const ITEMS = 'modules/Intelligence/database/migrations/2026_11_24_000100_create_intelligence_tables.php';

    private const LINKS = 'modules/Intelligence/database/migrations/2026_11_25_000100_create_claim_evidence_links_table.php';

    private const REVIEWS = 'modules/Intelligence/database/migrations/2026_11_26_000100_create_verification_reviews_table.php';

    private const RETRACTIONS = 'modules/Intelligence/database/migrations/2026_11_27_000100_create_retractions_table.php';

    private const REREVIEW = 'modules/Intelligence/database/migrations/2026_11_28_000100_create_rereview_flags_table.php';

    private User $analyst;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        foreach ([$this->analyst, $this->director] as $user) {
            DB::table('access_grants')->insert([
                'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
                'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('intelligence_items')) {
            $migrator = DB::connection('migrator');
            $migrator->table('intelligence_items')->update(['supersedes_id' => null]); // RESTRICT on the replacement's link
            foreach (['rereview_flags', 'retractions', 'verification_reviews'] as $table) {
                if (Schema::hasTable($table)) {
                    $migrator->table($table)->delete(); // RESTRICT: before the items
                }
            }
            $migrator->table('intelligence_items')->delete(); // cascades to subjects, claims and their links
            $migrator->table('evidence_custody_events')->delete();
            $migrator->table('evidence_assets')->delete();
            $migrator->table('evidence_blobs')->delete();
            $migrator->table('access_grants')->where('scope_type', 'competitor')->delete(); // the grant migration refuses to roll back over them
            DB::disconnect('migrator');
        }

        parent::tearDown();
    }

    public function test_concurrent_claim_moves_and_links_from_one_version_have_one_winner(): void
    {
        $competitor = $this->competitor('Acme');
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $item = $this->item($competitor);
            $claims = array_map(fn (int $n) => app(IntelligenceClaimService::class)->add($this->analyst, $item, [
                'statement' => "Claim $n", 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version,
            ]), [1, 2, 3]);
            $version = $item->version;
            $move = fn (IntelligenceClaim $claim, string $direction) => fn () => app(IntelligenceClaimService::class)
                ->move($this->analyst, $item, $claim->ulid, ['direction' => $direction, 'version' => $version]);

            $results = Concurrently::run([$move($claims[1], 'up'), $move($claims[2], 'up'), $move($claims[0], 'down'), $move($claims[1], 'down')]);
            $this->assertOneWinner($results, "moves, round $round");
            $this->assertSame([1, 2, 3], $item->claims()->orderBy('position')->pluck('position')->all());
            $this->assertCount(3, array_unique($item->claims()->orderBy('position')->pluck('ulid')->all()), 'every claim keeps one place');
            $this->assertSame($version + 1, $item->refresh()->version);

            $asset = $this->available();
            $version = $item->version;
            // Different claims of the item, and different relations, citing one asset (TASK-0086).
            $results = Concurrently::run(array_map(fn (array $c) => fn () => app(IntelligenceClaimService::class)
                ->link($this->analyst, $item, $claims[$c[0]]->ulid, ['evidence' => $asset, 'relation' => $c[1], 'version' => $version]), [[0, 'supports'], [1, 'contradicts'], [2, 'context'], [0, 'context']]));
            $this->assertOneWinner($results, "links, round $round");
            $this->assertSame(1, ClaimEvidenceLink::query()->whereIn('intelligence_claim_id', array_map(fn (IntelligenceClaim $c): int => $c->id, $claims))->count());
            $this->assertSame(1, DB::table('evidence_custody_events')->where('event', 'linked')->whereJsonContains('details->item', $item->ulid)->count());
            $this->assertSame($version + 1, $item->refresh()->version);
        }
        $this->assertSame(self::ROUNDS, DB::table('audit_events')->where('action', 'intelligence.claim_moved')->count());
    }

    /** The item's transaction holds the competitor's share lock while the archive waits for it, and the reverse. */
    public function test_creating_an_item_and_archiving_its_competitor_are_ordered_by_the_competitor_row(): void
    {
        $signal = sys_get_temp_dir().'/cais-p141-'.bin2hex(random_bytes(6));

        // The item first: its transaction stalls after the share lock; the archive then waits, and finds an open item.
        $first = $this->competitor('Acme');
        $results = $this->race(
            function () use ($first, $signal): void {
                IntelligenceItem::created(function () use ($signal): void {
                    touch($signal);
                    usleep(1_500_000);
                });
                $this->item($first);
            },
            fn () => app(CompetitorService::class)->setLifecycle($this->director, $first, 'archived'),
            $signal,
        );
        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('active', $first->refresh()->lifecycle);
        $this->assertSame(1, IntelligenceItem::query()->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $first->id))->count());

        // The archive first: it stalls holding the row; the item then waits for its share lock, and finds it archived.
        $second = $this->competitor('Globex');
        $results = $this->race(
            function () use ($second, $signal): void {
                Competitor::saving(function () use ($signal): void {
                    touch($signal);
                    usleep(1_500_000);
                });
                app(CompetitorService::class)->setLifecycle($this->director, $second, 'archived');
            },
            fn () => $this->item($second),
            $signal,
        );
        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('archived', $second->refresh()->lifecycle);
        $this->assertSame(0, IntelligenceItem::query()->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $second->id))->count());
    }

    /**
     * TASK-0086: two edits of one item from the same version, each adding a different subject to an item whose claim
     * cites evidence: one wins, and the evidence inherits exactly the winner's subject, recorded once.
     */
    public function test_concurrent_subject_edits_from_one_version_inherit_once(): void
    {
        [$a, $b] = [$this->competitor('Acme'), $this->competitor('Globex')];
        $market = DB::table('markets')->insertGetId([
            'ulid' => (string) Str::ulid(), 'code' => 'PH-NCR', 'name' => 'Metro Manila', 'country' => 'PH', 'default_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $marketUlid = (string) DB::table('markets')->where('id', $market)->value('ulid');
        $item = $this->item($a);
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, ['statement' => 'A', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        $asset = $this->available();
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]);
        $edit = fn (array $subjects) => fn () => app(IntelligenceItemService::class)->update($this->analyst, $item, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'version' => 3,
        ] + $subjects + ['competitors' => [$a->ulid], 'markets' => []]);

        $results = Concurrently::run([$edit(['competitors' => [$a->ulid, $b->ulid]]), $edit(['markets' => [$marketUlid]]), $edit(['competitors' => [$a->ulid, $b->ulid]])]);
        $this->assertOneWinner($results, 'subject edits');

        $item->refresh();
        $assetId = (int) DB::table('evidence_assets')->where('ulid', $asset)->value('id');
        $scopes = [
            DB::table('evidence_asset_competitor')->where('evidence_asset_id', $assetId)->orderBy('competitor_id')->pluck('competitor_id')->map(fn ($id) => (int) $id)->all(),
            DB::table('evidence_asset_market')->where('evidence_asset_id', $assetId)->pluck('market_id')->map(fn ($id) => (int) $id)->all(),
        ];
        $this->assertSame(4, $item->version);
        $this->assertSame($item->marketIds() === [] ? [[$a->id, $b->id], []] : [[$a->id], [(int) $market]], $scopes, 'exactly the winner\'s subject');
        $this->assertSame(2, DB::table('evidence_custody_events')->where('evidence_asset_id', $assetId)->where('event', 'scope_inherited')->count(), 'on citing, then once for the winner');
    }

    /**
     * TASK-0087: evidence withdrawn while a submission waits for its share lock: the submission sees it and is refused,
     * so no item reaches review supported by evidence that is gone.
     */
    public function test_a_submission_waiting_on_evidence_being_withdrawn_is_refused(): void
    {
        $signal = sys_get_temp_dir().'/cais-p142-'.bin2hex(random_bytes(6));
        $item = $this->item($this->competitor('Acme'));
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, ['statement' => 'A', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        $asset = $this->available();
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]);

        $results = $this->race(
            function () use ($asset, $signal): void {
                DB::transaction(function () use ($asset, $signal): void {
                    DB::table('evidence_assets')->where('ulid', $asset)->lockForUpdate()->first();
                    DB::table('evidence_assets')->where('ulid', $asset)->update(['status' => 'withdrawn', 'evidence_blob_id' => null]);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceWorkflowService::class)->submit($this->analyst, $item, ['version' => 3]),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('draft', $item->refresh()->status);
    }

    /**
     * P1-36b: a link waiting for the asset's row lock judges visibility from the scopes committed while it waited, not
     * from its transaction's earlier snapshot: a writer who cannot see competitor B never cites evidence that has just
     * inherited B.
     */
    public function test_a_waiting_link_sees_scopes_inherited_while_it_waited(): void
    {
        $signal = sys_get_temp_dir().'/cais-p136b-'.bin2hex(random_bytes(6));
        [$a, $b] = [$this->competitor('Acme'), $this->competitor('Globex')];
        $narrow = $this->createUser('narrow@example.test', 'ci_analyst', clearance: Classification::Confidential);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $narrow->id, 'scope_type' => 'competitor', 'competitor_id' => $a->id, 'max_classification' => 'confidential',
            'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $asset = $this->available(); // internal, unscoped: both writers see it now
        $aboutB = $this->item($b);
        $claimB = app(IntelligenceClaimService::class)->add($this->analyst, $aboutB, ['statement' => 'B', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        $aboutA = app(IntelligenceItemService::class)->create($narrow, ['title' => 'About A', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$a->ulid]]);
        $claimA = app(IntelligenceClaimService::class)->add($narrow, $aboutA, ['statement' => 'A', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);

        $results = $this->race(
            function () use ($aboutB, $claimB, $asset, $signal): void {
                EvidenceCustodyEvent::creating(function (EvidenceCustodyEvent $event) use ($signal): void {
                    if ($event->event === 'scope_inherited') {
                        touch($signal); // B is inherited, uncommitted, and the asset row is locked
                        usleep(1_500_000);
                    }
                });
                app(IntelligenceClaimService::class)->link($this->analyst, $aboutB, $claimB->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]);
            },
            fn () => app(IntelligenceClaimService::class)->link($narrow, $aboutA, $claimA->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame([$claimB->id], ClaimEvidenceLink::query()->pluck('intelligence_claim_id')->all());
        $this->assertSame([$b->id], DB::table('evidence_asset_competitor')->join('evidence_assets', 'evidence_assets.id', '=', 'evidence_asset_id')
            ->where('evidence_assets.ulid', $asset)->pluck('competitor_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * P1-43: a reviewer's decision waiting for the item's row lock while a director reassigns the review sees the new
     * assignment once it gets the lock, and is refused: only the assigned reviewer decides.
     */
    public function test_a_decision_waiting_on_a_reassignment_is_refused(): void
    {
        $signal = sys_get_temp_dir().'/cais-p143-'.bin2hex(random_bytes(6));
        [$reviewer, $other] = [$this->reviewer('reviewer@example.test'), $this->reviewer('other@example.test')];
        $item = $this->submitted();
        $workflow = app(IntelligenceWorkflowService::class);
        $workflow->assign($this->director, $item, ['reviewer' => $reviewer->ulid]);
        $workflow->startReview($reviewer, $item);
        $version = $item->refresh()->version;

        $results = $this->race(
            function () use ($item, $other, $signal): void {
                DB::transaction(function () use ($item, $other, $signal): void {
                    app(IntelligenceWorkflowService::class)->assign($this->director, $item, ['reviewer' => $other->ulid, 'rationale' => 'Rebalancing the queue']);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceWorkflowService::class)->decide($reviewer, $item, ['decision' => 'approve', 'version' => $version]),
            $signal,
        );

        $this->assertSame(['ok', AuthorizationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $item->refresh();
        $this->assertSame(['submitted', $other->id], [$item->status, $item->assigned_reviewer_id]);
        $this->assertSame(['assign', 'reassign'], VerificationReview::query()->orderBy('id')->pluck('decision')->all());
    }

    /**
     * TASK-0089: an approval waiting for the supporting evidence's row while it is withdrawn sees the withdrawal once
     * it gets the share lock, and is refused.
     */
    public function test_an_approval_waiting_on_evidence_being_withdrawn_is_refused(): void
    {
        $signal = sys_get_temp_dir().'/cais-p143w-'.bin2hex(random_bytes(6));
        $reviewer = $this->reviewer('reviewer@example.test');
        $item = $this->submitted();
        $workflow = app(IntelligenceWorkflowService::class);
        $workflow->assign($this->director, $item, ['reviewer' => $reviewer->ulid]);
        $workflow->startReview($reviewer, $item);
        $asset = (int) DB::table('claim_evidence_links')->value('evidence_asset_id');
        $version = $item->refresh()->version;

        $results = $this->race(
            function () use ($asset, $signal): void {
                DB::transaction(function () use ($asset, $signal): void {
                    DB::table('evidence_assets')->where('id', $asset)->lockForUpdate()->first();
                    DB::table('evidence_assets')->where('id', $asset)->update(['status' => 'withdrawn', 'evidence_blob_id' => null]);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceWorkflowService::class)->decide($reviewer, $item, ['decision' => 'approve', 'version' => $version]),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('in_review', $item->refresh()->status);
        $this->assertSame(['assign'], VerificationReview::query()->pluck('decision')->all());
    }

    /**
     * P1-44: two replacements of one published item, published at once: the original's row (locked with each, in ID
     * order) lets one supersede it; the other then finds it no longer published and is refused.
     */
    public function test_two_replacements_published_at_once_supersede_the_original_once(): void
    {
        $signal = sys_get_temp_dir().'/cais-p144-'.bin2hex(random_bytes(6));
        $reviewer = $this->reviewer('reviewer@example.test');
        $original = $this->submitted();
        DB::table('intelligence_items')->where('id', $original->id)->update(['status' => 'published', 'released_at' => now()]);
        [$first, $second] = [$this->approvedReplacement($original->refresh(), $reviewer), $this->approvedReplacement($original, $reviewer)];

        $results = $this->race(
            function () use ($first, $signal): void {
                DB::transaction(function () use ($first, $signal): void {
                    app(IntelligenceWorkflowService::class)->publish($this->director, $first, ['version' => $first->version]);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceWorkflowService::class)->publish($this->director, $second, ['version' => $second->version]),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame(['superseded', 'published', 'approved'], [$original->refresh()->status, $first->refresh()->status, $second->refresh()->status]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.superseded')->count());
    }

    /**
     * TASK-0090 #2: a replacement drafted while the original is being retracted waits for the original's row (share
     * lock versus FOR UPDATE), sees the retraction, and is refused: no draft replaces a retracted item.
     */
    public function test_a_replacement_drafted_during_a_retraction_is_refused(): void
    {
        $signal = sys_get_temp_dir().'/cais-p144r-'.bin2hex(random_bytes(6));
        $original = $this->submitted();
        DB::table('intelligence_items')->where('id', $original->id)->update(['status' => 'published', 'released_at' => now()]);
        $original->refresh();

        $results = $this->race(
            function () use ($original, $signal): void {
                DB::transaction(function () use ($original, $signal): void {
                    app(IntelligenceWorkflowService::class)->retract($this->director, $original, ['reason' => 'Synthetic retraction']);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceItemService::class)->draftReplacement($this->analyst, $original),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('retracted', $original->refresh()->status);
        $this->assertSame(0, IntelligenceItem::query()->whereNotNull('supersedes_id')->count());
    }

    /** TASK-0090: two directors publishing one item at once: one publication, one review row; the other is refused. */
    public function test_two_directors_publishing_one_item_publish_it_once(): void
    {
        $signal = sys_get_temp_dir().'/cais-p144p-'.bin2hex(random_bytes(6));
        $reviewer = $this->reviewer('reviewer@example.test');
        $deputy = $this->enableTwoFactor($this->createUser('deputy@example.test', 'ci_director', clearance: Classification::Confidential));
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $deputy->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
            'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = $this->submitted();
        $workflow = app(IntelligenceWorkflowService::class);
        $workflow->assign($this->director, $item, ['reviewer' => $reviewer->ulid]);
        $workflow->startReview($reviewer, $item);
        $workflow->decide($reviewer, $item, ['decision' => 'approve', 'version' => 3]);

        $results = $this->race(
            function () use ($item, $signal): void {
                DB::transaction(function () use ($item, $signal): void {
                    app(IntelligenceWorkflowService::class)->publish($this->director, $item, ['version' => 3]);
                    touch($signal);
                    usleep(1_500_000);
                });
            },
            fn () => app(IntelligenceWorkflowService::class)->publish($deputy, $item, ['version' => 3]),
            $signal,
        );

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('published', $item->refresh()->status);
        $this->assertSame([$this->director->id], VerificationReview::query()->where('decision', 'publish')->pluck('reviewer_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.published')->count());
    }

    public function test_the_migrations_refuse_to_roll_back_while_intelligence_exists(): void
    {
        $tables = fn (): array => [Schema::hasTable('claim_evidence_links'), Schema::hasTable('intelligence_items')];
        // P1-48: flags for re-review and their clearing first (they refer to items and evidence, and change the reviews' checks).
        $flagged = $this->item($this->competitor('Globex'));
        DB::table('intelligence_items')->where('id', $flagged->id)->update(['status' => 'approved', 'rereview_since' => now()]);
        $assetId = (int) DB::table('evidence_assets')->where('ulid', $this->available())->value('id');
        DB::table('rereview_flags')->insert(['ulid' => (string) Str::ulid(), 'intelligence_item_id' => $flagged->id, 'evidence_asset_id' => $assetId,
            'cause' => 'evidence_withdrawn', 'item_version' => 1, 'raised_at' => now()]);
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::REREVIEW, '--force' => true]), RuntimeException::class, 'Re-review flags exist');
        DB::connection('migrator')->table('rereview_flags')->delete();
        DB::table('verification_reviews')->insert(['ulid' => (string) Str::ulid(), 'intelligence_item_id' => $flagged->id, 'item_version' => 1, 'reviewer_id' => $this->director->id,
            'decision' => 'clear_rereview', 'rationale' => 'Synthetic', 'content_hash' => str_repeat('a', 64), 'decided_at' => now()]);
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::REREVIEW, '--force' => true]), RuntimeException::class, 'Re-review clearances exist');
        DB::connection('migrator')->table('verification_reviews')->delete();
        DB::connection('migrator')->table('intelligence_items')->where('id', $flagged->id)->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::REREVIEW, '--force' => true]));
        $this->assertSame([false, false], [Schema::hasTable('rereview_flags'), Schema::hasColumn('intelligence_items', 'rereview_since')]);

        // P1-44: retractions next (they refer to items).
        $retracted = $this->item($this->competitor('Hooli'));
        DB::table('intelligence_items')->where('id', $retracted->id)->update(['status' => 'retracted']);
        DB::table('retractions')->insert(['intelligence_item_id' => $retracted->id, 'reason' => 'Synthetic', 'retracted_by' => $this->director->id, 'retracted_at' => now()]);
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::RETRACTIONS, '--force' => true]), RuntimeException::class, 'Retractions exist');
        DB::connection('migrator')->table('retractions')->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::RETRACTIONS, '--force' => true]));
        $this->assertFalse(Schema::hasTable('retractions'));

        $reviewer = $this->reviewer('reviewer@example.test');
        $reviewed = $this->submitted();
        app(IntelligenceWorkflowService::class)->assign($this->director, $reviewed, ['reviewer' => $reviewer->ulid]);
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::REVIEWS, '--force' => true]), RuntimeException::class, 'Verification reviews exist');
        $this->assertTrue(Schema::hasTable('verification_reviews'));
        try {
            DB::connection('migrator')->table('intelligence_items')->where('id', $reviewed->id)->delete();
            $this->fail('A reviewed item was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('verification_reviews', $e->getMessage());
        }
        DB::connection('migrator')->table('verification_reviews')->delete();
        DB::connection('migrator')->table('intelligence_items')->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::REVIEWS, '--force' => true]));
        $this->assertFalse(Schema::hasColumn('intelligence_items', 'submitted_at'));

        $item = $this->item($this->competitor('Acme'));
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, [
            'statement' => 'Claim', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1,
        ]);
        $asset = $this->available();
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]);

        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::LINKS, '--force' => true]), RuntimeException::class, 'Claim evidence links exist');
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::ITEMS, '--force' => true]), RuntimeException::class, 'Intelligence items exist');
        $this->assertSame([true, true], $tables());
        $this->assertSame(1, ClaimEvidenceLink::query()->count());
        // Cited evidence cannot be removed, even by the migrator.
        try {
            DB::connection('migrator')->table('evidence_assets')->where('ulid', $asset)->delete();
            $this->fail('Cited evidence was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('claim_evidence_links', $e->getMessage());
        }

        DB::connection('migrator')->table('intelligence_items')->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::LINKS, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::ITEMS, '--force' => true]));
        $this->assertSame([false, false], $tables());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::ITEMS, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::LINKS, '--force' => true]));
        $this->assertSame([true, true], $tables());

        // Re-applied, the reviews migration ages an item already waiting from its last change; a draft has no age.
        $waiting = $this->item($this->competitor('Initech'));
        $draft = $this->item($this->competitor('Umbrella'));
        DB::table('intelligence_items')->where('id', $waiting->id)->update(['status' => 'submitted', 'updated_at' => '2026-11-01 08:00:00.000000']);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::REVIEWS, '--force' => true]));
        $this->assertSame('2026-11-01 08:00:00', $waiting->refresh()->submitted_at?->format('Y-m-d H:i:s'));
        $this->assertNull($draft->refresh()->submitted_at);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::RETRACTIONS, '--force' => true]));
        $this->assertTrue(Schema::hasTable('retractions'));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::REREVIEW, '--force' => true]));
        $this->assertSame([true, true], [Schema::hasTable('rereview_flags'), Schema::hasColumn('intelligence_items', 'rereview_since')]);
    }

    private function reviewer(string $email): User
    {
        $user = $this->createUser($email, 'ci_analyst', clearance: Classification::Confidential);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
            'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** A replacement of $original with one supported claim, submitted, assigned to $reviewer and approved. */
    private function approvedReplacement(IntelligenceItem $original, User $reviewer): IntelligenceItem
    {
        $item = app(IntelligenceItemService::class)->draftReplacement($this->analyst, $original);
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, ['statement' => 'A', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $this->available(), 'relation' => 'supports', 'version' => 2]);
        $workflow = app(IntelligenceWorkflowService::class);
        $workflow->submit($this->analyst, $item, ['version' => 3]);
        $workflow->assign($this->director, $item, ['reviewer' => $reviewer->ulid]);
        $workflow->startReview($reviewer, $item);
        $workflow->decide($reviewer, $item, ['decision' => 'approve', 'version' => 3]);

        return $item->refresh();
    }

    /** An item with one claim supported by available evidence, submitted. */
    private function submitted(): IntelligenceItem
    {
        $item = $this->item($this->competitor('Acme'));
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, ['statement' => 'A', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $this->available(), 'relation' => 'supports', 'version' => 2]);
        app(IntelligenceWorkflowService::class)->submit($this->analyst, $item, ['version' => 3]);

        return $item->refresh();
    }

    /**
     * Runs $stalling, which signals from inside its transaction and then holds it, against $waiting, started once the
     * signal is seen.
     *
     * @return list<array{outcome: string, message: string}>
     */
    private function race(callable $stalling, callable $waiting, string $signal): array
    {
        try {
            return Concurrently::run([$stalling, function () use ($waiting, $signal): void {
                $deadline = microtime(true) + 30;
                while (! is_file($signal)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('The first transaction never started.');
                    }
                    usleep(20_000);
                }
                $waiting();
            }], 60.0);
        } finally {
            if (file_exists($signal)) {
                unlink($signal);
            }
        }
    }

    private function item(Competitor $competitor): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$competitor->ulid],
        ]);
    }

    private function competitor(string $name): Competitor
    {
        $id = (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Competitor::query()->findOrFail($id);
    }

    /** An available asset's ULID. */
    private function available(): string
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $ulid = (string) Str::ulid();
        DB::table('evidence_assets')->insert([
            'ulid' => $ulid, 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob,
            'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence', 'captured_at' => now()->subDay(),
            'uploaded_by' => $this->analyst->id, 'classification' => 'internal', 'retention_until' => now('UTC')->addDays(30)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ulid;
    }

    /** @param  list<array{outcome: string, message: string}>  $results */
    private function assertOneWinner(array $results, string $scenario): void
    {
        foreach ($results as $i => $result) {
            $this->assertContains($result['outcome'], ['ok', ValidationException::class], "$scenario, request $i: {$result['outcome']} {$result['message']}");
        }
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok')), "$scenario: ".json_encode($results));
    }
}
