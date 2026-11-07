<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceIntegrityService;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceWithdrawalService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\RereviewFlag;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Intelligence\Outbox\FlagCitingItems;
use Modules\Platform\Classification;
use Modules\Platform\Outbox\OutboxMessage;
use Modules\Platform\Outbox\OutboxRelay;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-48 / docs/03 Evidence ("Evidence state after publication") and the state machine; PO decisions O13 and 2026-11-07:
 * when evidence is withdrawn or fails its integrity check, every approved or published item citing it is flagged for
 * re-review through the outbox. Nothing changes status and nothing is retracted: a published item stays readable with
 * an "Under re-review" banner; a flagged item cannot be published until a director clears the flag (with a reason, and
 * only if every claim is still supported by evidence they can see), or it is retracted or replaced.
 */
class IntelligenceRereviewTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private User $reviewer;

    private User $director;

    private User $reader;

    private string $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->reviewer = $this->createUser('reviewer@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        // Reads intelligence, not evidence: cited evidence is never described to them.
        $this->reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        foreach ([$this->analyst, $this->reviewer, $this->director, $this->reader] as $user) {
            $this->grantAll($user);
        }
        $this->acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $this->acme, 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_withdrawn_evidence_flags_a_published_item_that_stays_readable(): void
    {
        $evidence = $this->available();
        $item = $this->published([$evidence]);
        app(EvidenceWithdrawalService::class)->withdraw($this->director, $evidence, 'Fabricated screenshot');
        $this->assertNull($item->refresh()->rereview_since, 'flagged when the event is delivered');

        $this->assertSame(1, $this->relay()['dispatched']);

        $item->refresh();
        $this->assertSame('published', $item->status, 'nothing is retracted or hidden');
        $flag = RereviewFlag::query()->sole();
        $this->assertSame([$item->id, $evidence->id, 'evidence_withdrawn', $item->version], [$flag->intelligence_item_id, $flag->evidence_asset_id, $flag->cause, $flag->item_version]);
        $this->assertEquals($flag->raised_at, $item->rereview_since);
        $audit = DB::table('audit_events')->where('action', 'intelligence.rereview_flagged')->sole();
        $this->assertSame(['system', null, $item->ulid], [$audit->actor_type, $audit->actor_id, $audit->subject_ulid]);
        $this->assertEquals(['cause' => 'evidence_withdrawn', 'evidence_ulid' => $evidence->ulid, 'flag_ulid' => $flag->ulid, 'version' => $item->version], json_decode((string) $audit->context, true));
        $this->assertSame(0, DB::table('retractions')->count());

        // Every reader sees the banner; the evidence is named only to those who can see it.
        $this->actingAs($this->reader)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('rereview.since', $item->rereview_since?->toIso8601ZuluString())
            ->where('rereview.flags.0.cause', 'evidence_withdrawn')->where('rereview.flags.0.evidence', null)->where('rereview.canClear', false));
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('rereview.flags.0.evidence.ulid', $evidence->ulid)->where('rereview.canClear', true)
            ->where('rereview.clearProblems', ['Claim 1 cites no available evidence that you can see and that supports it.']));
        $this->actingAs($this->reader)->get("/intelligence/{$item->ulid}/provenance")->assertInertia(fn ($page) => $page
            ->where('flags.0.ulid', $flag->ulid)->where('flags.0.open', true)->where('flags.0.evidence', null));

        // Its only support is gone: it cannot be cleared, only retracted (or replaced).
        $this->expectValidation(fn () => $this->workflow()->clearRereview($this->director, $item, ['rationale' => 'Still fine', 'version' => $item->version]), 'claims');
        $this->workflow()->retract($this->director, $item, ['reason' => 'Its evidence was withdrawn']);
        $this->assertSame('retracted', $item->refresh()->status);
        $this->actingAs($this->reader)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('rereview', null));
    }

    public function test_a_failed_integrity_check_holds_publication_until_a_director_clears_it(): void
    {
        [$broken, $sound, $later] = [$this->available(), $this->available(), $this->available()];
        $item = $this->approved([$broken, $sound, $later]);
        $this->assertSame('missing', app(EvidenceIntegrityService::class)->verify((int) $broken->evidence_blob_id), 'its bytes are not in the store');
        $this->relay();

        $item->refresh();
        $this->assertSame(['approved', 'evidence_integrity_failed'], [$item->status, RereviewFlag::query()->sole()->cause]);
        $held = 'Evidence it cites was withdrawn or failed an integrity check. A director must clear the re-review first.';
        $this->assertContains($held, $this->workflow()->publicationProblems($item, $this->director));
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item, ['version' => $item->version]), 'item', $held);

        // Clearing: a director, from the version read, with a reason.
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/rereview/clear", ['rationale' => 'Fine', 'version' => $item->version])->assertForbidden();
        $this->expectValidation(fn () => $this->workflow()->clearRereview($this->director, $item, ['rationale' => '  ', 'version' => $item->version]), 'rationale');
        $this->expectValidation(fn () => $this->workflow()->clearRereview($this->director, $item, ['rationale' => 'Fine', 'version' => $item->version + 1]), 'version');
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/rereview/clear", ['rationale' => "Two other sources\nstill support it", 'version' => $item->version])
            ->assertSessionHasNoErrors()->assertRedirect("/intelligence/{$item->ulid}");

        $item->refresh();
        $this->assertNull($item->rereview_since);
        $review = VerificationReview::query()->where('decision', 'clear_rereview')->sole();
        $this->assertSame([$this->director->id, "Two other sources\nstill support it", $item->version], [$review->reviewer_id, $review->rationale, $review->item_version]);
        $this->assertEquals(['review_ulid' => $review->ulid, 'flags' => 1, 'version' => $item->version],
            json_decode((string) DB::table('audit_events')->where('action', 'intelligence.rereview_cleared')->sole()->context, true));
        $this->expectValidation(fn () => $this->workflow()->clearRereview($this->director, $item, ['rationale' => 'Again', 'version' => $item->version]), 'item', 'This item is not flagged for re-review.');

        // Published once cleared; a later failure flags it again, and only the new flag is open.
        $this->workflow()->publish($this->director, $item, ['version' => $item->version]);
        app(EvidenceWithdrawalService::class)->withdraw($this->director, $later, 'Superseded by a better copy');
        $this->relay();
        $item->refresh();
        $this->assertSame('published', $item->status);
        $this->assertNotNull($item->rereview_since);
        $this->assertSame(['evidence_withdrawn'], $this->workflow()->openFlags($item)->pluck('cause')->all());
        $this->actingAs($this->reader)->get("/intelligence/{$item->ulid}/provenance")->assertInertia(fn ($page) => $page
            ->where('flags.0.open', false)->where('flags.1.open', true));
    }

    public function test_only_approved_and_published_items_citing_the_evidence_are_flagged_once(): void
    {
        $shared = $this->available();
        $published = $this->published([$shared]);
        $other = $this->published([$this->available()]);
        $retracted = $this->published([$shared]);
        $this->workflow()->retract($this->director, $retracted, ['reason' => 'Out of date']);
        $draft = $this->draft();
        $claim = $this->claims()->add($this->analyst, $draft, ['statement' => 'Acme sells it', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $draft->version]);
        $this->claims()->link($this->analyst, $draft->refresh(), $claim->ulid, ['evidence' => $shared->ulid, 'relation' => 'context', 'version' => $draft->version]);
        // A claim withdrawn before approval: its citation no longer counts.
        $withdrawnClaim = $this->draft();
        $kept = $this->claims()->add($this->analyst, $withdrawnClaim, ['statement' => 'Kept', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $withdrawnClaim->version]);
        $this->claims()->link($this->analyst, $withdrawnClaim->refresh(), $kept->ulid, ['evidence' => $this->available()->ulid, 'relation' => 'supports', 'version' => $withdrawnClaim->version]);
        $dropped = $this->claims()->add($this->analyst, $withdrawnClaim->refresh(), ['statement' => 'Dropped', 'claim_type' => 'rumor', 'source_reliability' => 'C', 'info_credibility' => 3, 'version' => $withdrawnClaim->version]);
        $this->claims()->link($this->analyst, $withdrawnClaim->refresh(), $dropped->ulid, ['evidence' => $shared->ulid, 'relation' => 'supports', 'version' => $withdrawnClaim->version]);
        $this->claims()->withdraw($this->analyst, $withdrawnClaim->refresh(), $dropped->ulid, ['version' => $withdrawnClaim->version]);
        $this->workflow()->submit($this->analyst, $withdrawnClaim->refresh(), ['version' => $withdrawnClaim->version]);
        $withdrawnClaim = $this->approve($withdrawnClaim->refresh());

        app(EvidenceWithdrawalService::class)->withdraw($this->director, $shared, 'Not reliable');
        $this->relay();

        $this->assertSame([$published->id], RereviewFlag::query()->pluck('intelligence_item_id')->all());
        foreach ([$other, $retracted, $draft, $withdrawnClaim] as $untouched) {
            $this->assertNull($untouched->refresh()->rereview_since);
        }

        // Delivered again (at least once): nothing is added.
        $event = DB::table('outbox_events')->sole();
        app(FlagCitingItems::class)->handle(new OutboxMessage($event->ulid, $event->event_type, $event->aggregate_type, (int) $event->aggregate_id, ['evidence_ulid' => $shared->ulid], 2));
        $this->assertSame(1, RereviewFlag::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.rereview_flagged')->count());
    }

    public function test_clearing_is_404_on_a_hidden_item_and_never_by_its_author_or_owner(): void
    {
        $evidence = $this->available();
        $secret = $this->published([$this->available()], classification: 'confidential');
        $item = $this->approved([$evidence, $this->available()]);
        DB::table('intelligence_items')->whereIn('id', [$secret->id, $item->id])->update(['rereview_since' => now()]);

        $outsider = $this->enableTwoFactor($this->createUser('outsider@example.test', 'ci_director', clearance: Classification::Internal));
        $this->grantAll($outsider);
        $this->actingAs($outsider)->post("/intelligence/{$secret->ulid}/rereview/clear", [])->assertNotFound();
        $this->actingAs($outsider)->post("/intelligence/{$secret->ulid}/rereview/clear", ['rationale' => str_repeat('x', 6000), 'version' => 'x'])->assertNotFound();

        DB::table('intelligence_items')->where('id', $item->id)->update(['owner_id' => $this->director->id]);
        $this->assertThrows(fn () => $this->workflow()->clearRereview($this->director, $item->refresh(), ['rationale' => 'Fine', 'version' => $item->version]), AuthorizationException::class);
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('rereview.canClear', false));
        $this->assertNotNull($item->refresh()->rereview_since);
        $this->assertSame(0, VerificationReview::query()->where('decision', 'clear_rereview')->count());
    }

    public function test_flags_are_append_only_and_checked_by_the_database(): void
    {
        $evidence = $this->available();
        $item = $this->published([$evidence]);
        app(EvidenceWithdrawalService::class)->withdraw($this->director, $evidence, 'Not reliable');
        $this->relay();
        $flag = RereviewFlag::query()->sole();
        $row = ['ulid' => (string) Str::ulid(), 'intelligence_item_id' => $item->id, 'evidence_asset_id' => $evidence->id, 'item_version' => 1, 'raised_at' => now()];

        $this->assertDatabaseRefuses(fn () => DB::table('rereview_flags')->where('id', $flag->id)->update(['cause' => 'evidence_integrity_failed']), 'UPDATE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('rereview_flags')->where('id', $flag->id)->delete(), 'DELETE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('rereview_flags')->insert($row + ['cause' => 'evidence_withdrawn']), 'Duplicate entry');
        $this->assertDatabaseRefuses(fn () => DB::table('rereview_flags')->insert($row + ['cause' => 'gossip']), 'Check constraint');
        $review = ['ulid' => (string) Str::ulid(), 'intelligence_item_id' => $item->id, 'item_version' => 1, 'reviewer_id' => $this->director->id,
            'decision' => 'clear_rereview', 'content_hash' => str_repeat('a', 64), 'decided_at' => now()];
        $this->assertDatabaseRefuses(fn () => DB::table('verification_reviews')->insert($review), 'Check constraint');
        DB::table('verification_reviews')->insert($review + ['rationale' => 'Synthetic reason']);
        $this->assertSame(1, VerificationReview::query()->where('decision', 'clear_rereview')->count());
    }

    private function draft(string $classification = 'internal'): IntelligenceItem
    {
        return $this->items()->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => 'Synthetic summary', 'item_type' => 'news', 'classification' => $classification,
            'high_impact' => false, 'competitors' => [$this->acme],
        ]);
    }

    /**
     * One claim supported by each of these assets, submitted, assigned, reviewed and approved.
     *
     * @param  list<EvidenceAsset>  $evidence
     */
    private function approved(array $evidence, string $classification = 'internal'): IntelligenceItem
    {
        $item = $this->draft($classification);
        $claim = $this->claims()->add($this->analyst, $item, ['statement' => 'Acme sells the widget', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version]);
        foreach ($evidence as $asset) {
            $this->claims()->link($this->analyst, $item->refresh(), $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => $item->version]);
        }
        $this->workflow()->submit($this->analyst, $item->refresh(), ['version' => $item->version]);

        return $this->approve($item->refresh());
    }

    private function approve(IntelligenceItem $item): IntelligenceItem
    {
        $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid]);
        $this->workflow()->startReview($this->reviewer, $item);
        $this->workflow()->decide($this->reviewer, $item, ['decision' => 'approve', 'version' => $item->version]);

        return $item->refresh();
    }

    /** @param  list<EvidenceAsset>  $evidence */
    private function published(array $evidence, string $classification = 'internal'): IntelligenceItem
    {
        $item = $this->approved($evidence, $classification);
        $this->workflow()->publish($this->director, $item, ['version' => $item->version]);

        return $item->refresh();
    }

    /** Available evidence; its blob's bytes are not on the (fake) store, so a re-hash finds them missing. */
    private function available(): EvidenceAsset
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob,
            'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence', 'captured_at' => now()->subDay(),
            'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset;
    }

    /** @return array{dispatched: int, retrying: int, dead: int, superseded: int} */
    private function relay(): array
    {
        $result = app(OutboxRelay::class)->run();
        $this->assertSame(0, $result['retrying'] + $result['dead'], 'every delivery succeeds');

        return $result;
    }

    private function grantAll(User $user): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function items(): IntelligenceItemService
    {
        return app(IntelligenceItemService::class);
    }

    private function claims(): IntelligenceClaimService
    {
        return app(IntelligenceClaimService::class);
    }

    private function workflow(): IntelligenceWorkflowService
    {
        return app(IntelligenceWorkflowService::class);
    }

    private function expectValidation(callable $call, string $field, ?string $message = null): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on $field.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            if ($message !== null) {
                $this->assertSame([$message], $e->errors()[$field]);
            }
        }
    }

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            $statement();
            $this->fail("The database accepted a statement it should refuse ($message).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
