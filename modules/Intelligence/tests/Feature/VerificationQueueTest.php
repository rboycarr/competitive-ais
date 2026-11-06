<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Intelligence\Support\IntelligenceContent;
use Modules\Platform\Classification;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-43 / docs/03 Intelligence (state machine: assign, start_review, approve, request_changes, reject), docs/07 §3
 * (segregation of duties): reviewers and directors work a queue of submitted and in-review items, oldest first, with
 * filters; a director assigns, and reassigns with a reason; only the assigned reviewer, never the author or owner,
 * starts the review and decides, from the version read, with a reason for requesting changes or rejecting; approval
 * needs every claim supported by available evidence the reviewer can see. Every assignment and decision is an
 * append-only review row bound to the version and content hash, and is audited without user text.
 */
class VerificationQueueTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private User $reviewer;

    private User $director;

    private string $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->reviewer = $this->createUser('reviewer@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        foreach ([$this->analyst, $this->reviewer, $this->director] as $user) {
            $this->grantAll($user);
        }
        $this->acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $this->acme, 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_director_assigns_and_the_assigned_reviewer_approves(): void
    {
        $item = $this->submitted();
        $this->assertNotNull($item->submitted_at);
        $hash = IntelligenceContent::hash($item);

        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('review.canAssign', true)->where('review.reviewer', null)
            // Not the author (who wrote it); the director may review, the reviewer too.
            ->where('review.reviewers', function ($list): bool {
                $shown = array_column(is_array($list) ? $list : $list->all(), 'ulid');
                $expected = [$this->reviewer->ulid, $this->director->ulid];
                sort($shown);
                sort($expected);

                return $shown === $expected;
            }));
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/assign", ['reviewer' => $this->reviewer->ulid])
            ->assertSessionHasNoErrors()->assertRedirect("/intelligence/{$item->ulid}");

        $item->refresh();
        $this->assertSame(['submitted', $this->reviewer->id], [$item->status, $item->assigned_reviewer_id]);
        $assign = VerificationReview::query()->sole();
        $this->assertSame(['assign', $this->director->id, $this->reviewer->id, $item->version, null, $hash],
            [$assign->decision, $assign->reviewer_id, $assign->assignee_id, $assign->item_version, $assign->rationale, $assign->content_hash]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.reviewer_assigned')->sole();
        $this->assertEquals(['decision' => 'assign', 'reviewer_ulid' => $this->reviewer->ulid, 'review_ulid' => $assign->ulid, 'version' => $item->version], json_decode((string) $audit->context, true));

        $this->actingAs($this->reviewer)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('review.canStart', true)->where('review.canDecide', false)->where('review.canAssign', false));
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/review")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('in_review', $item->refresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.review_started')->count());

        $this->actingAs($this->reviewer)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('review.canDecide', true)->where('review.approvalProblems', []));
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/decision", ['decision' => 'approve', 'version' => $item->version])
            ->assertSessionHasNoErrors()->assertRedirect();

        $item->refresh();
        $this->assertSame('approved', $item->status);
        $this->assertNotNull($item->verified_at);
        $approve = VerificationReview::query()->where('decision', 'approve')->sole();
        $this->assertSame([$this->reviewer->id, null, $item->version, $hash], [$approve->reviewer_id, $approve->assignee_id, $approve->item_version, $approve->content_hash]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.reviewed')->sole();
        $this->assertEquals(['decision' => 'approve', 'review_ulid' => $approve->ulid, 'version' => $item->version], json_decode((string) $audit->context, true));

        // The history is part of the item's record, for anyone who sees it.
        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('review.history.0.decision', 'assign')->where('review.history.0.assignee', $this->reviewer->name)
            ->where('review.history.1.decision', 'approve')->where('review.history.1.by', $this->reviewer->name)
            ->where('review.canAssign', false)->where('review.canDecide', false));
    }

    public function test_only_a_director_assigns_and_only_an_eligible_reviewer(): void
    {
        $item = $this->submitted();
        foreach ([$this->analyst, $this->reviewer] as $user) {
            $this->actingAs($user)->post("/intelligence/{$item->ulid}/assign", ['reviewer' => $this->reviewer->ulid])->assertForbidden();
        }

        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $suspended = $this->createUser('suspended@example.test', 'ci_analyst', 'suspended', Classification::Confidential);
        $blind = $this->createUser('blind@example.test', 'ci_analyst', clearance: Classification::Confidential); // no grant
        $this->grantAll($reader);
        $this->grantAll($suspended);
        foreach ([
            $this->analyst->ulid => 'The author or owner of an item cannot review it.',
            $reader->ulid => 'The reviewer must be an active user who reviews intelligence.',
            $suspended->ulid => 'The reviewer must be an active user who reviews intelligence.',
            $blind->ulid => 'The reviewer must be able to see this item.',
            (string) Str::ulid() => 'The reviewer must be an active user who reviews intelligence.',
        ] as $ulid => $message) {
            $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, ['reviewer' => $ulid]), 'reviewer', $message);
        }
        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, ['reviewer' => ['x']]), 'reviewer');
        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, []), 'reviewer');
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/assign", ['reviewer' => [$this->reviewer->ulid]])->assertSessionHasErrors('reviewer');

        // Only items in the queue.
        $draft = $this->draft();
        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $draft, ['reviewer' => $this->reviewer->ulid]), 'item');
        $this->assertSame(0, VerificationReview::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'intelligence.reviewer_assigned')->count());
    }

    public function test_reassignment_needs_a_reason_and_returns_the_item_to_the_new_reviewer(): void
    {
        $item = $this->inReview();
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($other);

        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, ['reviewer' => $other->ulid, 'rationale' => "  \n "]), 'rationale');
        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid, 'rationale' => 'Again']), 'reviewer', 'This reviewer is already assigned.');
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/assign", ['reviewer' => $other->ulid, 'rationale' => '  On leave until December  '])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(['submitted', $other->id], [$item->status, $item->assigned_reviewer_id]);
        $reassign = VerificationReview::query()->where('decision', 'reassign')->sole();
        $this->assertSame([$this->director->id, $other->id, 'On leave until December'], [$reassign->reviewer_id, $reassign->assignee_id, $reassign->rationale]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.reviewer_assigned')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('leave', (string) $audit?->context);
        $this->assertSame('reassign', json_decode((string) $audit?->context, true)['decision']);

        // The former reviewer can no longer act; the new one starts afresh.
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/decision", ['decision' => 'approve', 'version' => $item->version])->assertForbidden();
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/review")->assertForbidden();
        $this->actingAs($other)->post("/intelligence/{$item->ulid}/review")->assertSessionHasNoErrors();
        $this->assertSame('in_review', $item->refresh()->status);
    }

    public function test_only_the_assigned_reviewer_starts_and_decides(): void
    {
        $item = $this->submitted();
        $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid]);
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($other);

        foreach ([$this->director, $other, $this->analyst] as $user) {
            $this->actingAs($user)->post("/intelligence/{$item->ulid}/review")->assertForbidden();
        }
        // Not started yet: no decision.
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, ['decision' => 'approve', 'version' => $item->version]), 'item');

        $this->workflow()->startReview($this->reviewer, $item);
        $this->expectValidation(fn () => $this->workflow()->startReview($this->reviewer, $item), 'item');
        foreach ([$this->director, $other, $this->analyst] as $user) {
            $this->actingAs($user)->post("/intelligence/{$item->ulid}/decision", ['decision' => 'approve', 'version' => $item->version])->assertForbidden();
        }
        // The author is never the reviewer, even if assigned outside the service.
        DB::table('intelligence_items')->where('id', $item->id)->update(['assigned_reviewer_id' => $this->analyst->id]);
        $this->expectException(AuthorizationException::class);
        try {
            $this->workflow()->decide($this->analyst, $item, ['decision' => 'approve', 'version' => $item->version]);
        } finally {
            $this->assertSame(0, VerificationReview::query()->whereIn('decision', VerificationReview::DECISIONS)->count());
            $this->assertSame('in_review', $item->refresh()->status);
        }
    }

    public function test_changes_are_requested_with_a_reason_then_the_resubmitted_item_is_rejected(): void
    {
        $item = $this->inReview();
        $first = $item->version;
        $data = ['version' => $first];
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, $data), 'decision');
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, $data + ['decision' => 'publish']), 'decision');
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, $data + ['decision' => 'request_changes']), 'rationale');
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, $data + ['decision' => 'reject', 'rationale' => " \t "]), 'rationale');
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, $data + ['decision' => 'reject', 'rationale' => str_repeat('x', IntelligenceWorkflowService::MAX_RATIONALE + 1)]), 'rationale');
        $this->expectValidation(fn () => $this->workflow()->decide($this->reviewer, $item, ['decision' => 'reject', 'rationale' => 'No', 'version' => $first - 1]), 'version');
        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/decision", ['decision' => 'reject', 'rationale' => ['x'], 'version' => $first])->assertSessionHasErrors('rationale');

        $this->actingAs($this->reviewer)->post("/intelligence/{$item->ulid}/decision", ['decision' => 'request_changes', 'rationale' => 'Cite the price list for claim 1.', 'version' => $first])
            ->assertSessionHasNoErrors();
        $this->assertSame('changes_requested', $item->refresh()->status);
        $requested = VerificationReview::query()->where('decision', 'request_changes')->sole();
        $this->assertSame([$first, 'Cite the price list for claim 1.'], [$requested->item_version, $requested->rationale]);

        // Editable again; the edit changes the version and the content hash.
        $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => $first]);
        $claim = IntelligenceClaim::query()->where('intelligence_item_id', $item->id)->orderByDesc('id')->firstOrFail();
        $this->cite($item->refresh(), $claim, $this->available());
        $before = $item->refresh()->submitted_at ?? $this->fail('A submitted item has a submission time.');
        $this->travel(5)->minutes();
        $this->workflow()->submit($this->analyst, $item, ['version' => $item->version]);
        $item->refresh();
        $this->assertSame(['submitted', $this->reviewer->id], [$item->status, $item->assigned_reviewer_id], 'the reviewer stays assigned');
        $this->assertTrue($item->submitted_at?->greaterThan($before), 'the queue age restarts');

        $this->workflow()->startReview($this->reviewer, $item);
        $this->workflow()->decide($this->reviewer, $item, ['decision' => 'reject', 'rationale' => 'Not credible.', 'version' => $item->version]);
        $this->assertSame('rejected', $item->refresh()->status);
        $reject = VerificationReview::query()->where('decision', 'reject')->sole();
        $this->assertSame($item->version, $reject->item_version);
        $this->assertNotSame($requested->content_hash, $reject->content_hash);
        $this->assertSame(IntelligenceContent::hash($item), $reject->content_hash);

        // Rejected is final.
        $this->expectValidation(fn () => $this->workflow()->startReview($this->reviewer, $item), 'item');
        $this->expectValidation(fn () => $this->workflow()->assign($this->director, $item, ['reviewer' => $this->director->ulid, 'rationale' => 'x']), 'item');
        $this->assertSame(['assign', 'request_changes', 'reject'], VerificationReview::query()->orderBy('id')->pluck('decision')->all());
    }

    public function test_approval_needs_available_supporting_evidence_that_the_reviewer_can_see(): void
    {
        $item = $this->inReview();
        $asset = (int) DB::table('claim_evidence_links')->value('evidence_asset_id');
        $message = ['Claim 1 cites no available evidence that you can see and that supports it.'];

        // Withdrawn after submission (P1-48 will flag it; approval already refuses it).
        DB::table('evidence_assets')->where('id', $asset)->update(['status' => 'withdrawn', 'evidence_blob_id' => null, 'legal_hold' => false]);
        $this->actingAs($this->reviewer)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('review.approvalProblems', $message));
        $this->expectProblems($item, $message);

        // Available, but above the reviewer's clearance: the reviewer cannot check it.
        DB::table('evidence_assets')->where('id', $asset)->update(['status' => 'available', 'evidence_blob_id' => $this->blob(), 'classification' => 'restricted']);
        $this->expectProblems($item, $message);

        // Requesting changes needs no evidence.
        $this->workflow()->decide($this->reviewer, $item, ['decision' => 'request_changes', 'rationale' => 'The evidence is gone.', 'version' => $item->version]);
        $this->assertSame('changes_requested', $item->refresh()->status);
    }

    public function test_every_transition_is_404_for_a_hidden_item_whatever_the_input(): void
    {
        $item = $this->submitted();
        $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid]);
        DB::table('intelligence_items')->where('id', $item->id)->update(['classification' => 'restricted']);

        // Restricted items need a covering explicit grant: nobody has one here, the director included.
        foreach ([$this->director, $this->reviewer, $this->analyst] as $user) {
            foreach ([['assign', ['reviewer' => $this->reviewer->ulid]], ['assign', ['reviewer' => ['bad']]], ['review', []],
                ['decision', ['decision' => 'approve', 'version' => $item->version]], ['decision', ['decision' => 'nonsense', 'rationale' => ['x']]]] as [$path, $input]) {
                $this->actingAs($user)->post("/intelligence/{$item->ulid}/$path", $input)->assertNotFound();
            }
        }
        $this->assertSame('submitted', $item->refresh()->status);
        $this->assertSame(1, VerificationReview::query()->count());
    }

    public function test_the_queue_lists_waiting_items_oldest_first_with_filters(): void
    {
        $globex = $this->competitor('Globex');
        $old = $this->submitted();
        $mine = $this->submitted();
        $this->workflow()->assign($this->director, $mine, ['reviewer' => $this->reviewer->ulid]);
        $this->workflow()->startReview($this->reviewer, $mine);
        $urgent = $this->submitted(competitor: $globex, highImpact: true, classification: 'confidential');
        $draft = $this->draft();
        $approved = $this->inReview();
        $this->workflow()->decide($this->reviewer, $approved, ['decision' => 'approve', 'version' => $approved->version]);
        foreach ([[$old, 3], [$mine, 2], [$urgent, 1]] as [$item, $days]) {
            DB::table('intelligence_items')->where('id', $item->id)->update(['submitted_at' => now('UTC')->subDays($days)]);
        }

        $queue = fn (User $user, array $filters = []): array => array_column($this->actingAs($user)->get('/verification?'.http_build_query($filters))
            ->assertOk()->inertiaProps('items'), 'ulid');
        $this->assertSame([$old->ulid, $mine->ulid, $urgent->ulid], $queue($this->reviewer));
        $this->assertSame([$old->ulid, $mine->ulid, $urgent->ulid], $queue($this->director));
        $this->assertSame([$mine->ulid], $queue($this->reviewer, ['assigned' => 'mine']));
        $this->assertSame([$old->ulid, $urgent->ulid], $queue($this->reviewer, ['assigned' => 'unassigned']));
        $this->assertSame([$mine->ulid], $queue($this->reviewer, ['status' => 'in_review']));
        $this->assertSame([$urgent->ulid], $queue($this->reviewer, ['high_impact' => '1']));
        $this->assertSame([$old->ulid, $mine->ulid], $queue($this->reviewer, ['high_impact' => '0']));
        $this->assertSame([$urgent->ulid], $queue($this->reviewer, ['classification' => 'confidential']));
        $this->assertSame([$urgent->ulid], $queue($this->reviewer, ['competitor' => DB::table('competitors')->where('id', $globex)->value('ulid')]));
        $this->assertNotContains($draft->ulid, $queue($this->reviewer));
        $this->assertNotContains($approved->ulid, $queue($this->reviewer));

        $this->actingAs($this->reviewer)->get('/verification')->assertInertia(fn ($page) => $page
            ->where('items.1.reviewer', $this->reviewer->name)->where('items.1.mine', true)->where('items.0.reviewer', null)
            ->where('items.2.highImpact', true)->where('items.2.competitors', ['Globex']));
        $this->actingAs($this->reviewer)->get('/verification?assigned=everyone')->assertSessionHasErrors('assigned');
        $this->actingAs($this->reviewer)->get('/verification?status=draft')->assertSessionHasErrors('status');

        // For reviewers and directors only.
        foreach (['exec_reader', 'data_collector', 'sales_field', 'auditor'] as $role) {
            $user = $this->createUser("$role@example.test", $role, clearance: Classification::Confidential);
            $this->grantAll($user);
            $this->actingAs($user)->get('/verification')->assertForbidden();
        }
        $this->actingAs($this->reviewer)->get('/intelligence')->assertInertia(fn ($page) => $page->where('auth.can.verificationQueue', true));
    }

    public function test_the_queue_follows_the_matrix(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: intelligence.review, so the queue
        $records = AuthorizationMatrix::records();
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->queued($record['label'], $record['classification'], $record['competitors'], $record['markets']);
        }
        $gate = app(Gate::class);
        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = IntelligenceItem::seenBy(IntelligenceItem::query(), $entry['user'], $gate)->whereIn('status', IntelligenceItem::IN_QUEUE)->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'queue query');

        $active = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && $u['clearance'] === 'restricted'));
        $shown = [];
        foreach ($active as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/verification')->assertOk()->inertiaProps('items'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($active, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /verification');
    }

    public function test_reviews_are_append_only_and_checked_by_the_database(): void
    {
        $item = $this->inReview();
        $review = VerificationReview::query()->sole();
        $this->assertDatabaseRefuses(fn () => DB::table('verification_reviews')->where('id', $review->id)->update(['rationale' => 'Changed']), 'UPDATE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('verification_reviews')->where('id', $review->id)->delete(), 'DELETE command denied');

        $row = fn (array $values): array => $values + [
            'ulid' => (string) Str::ulid(), 'intelligence_item_id' => $item->id, 'item_version' => $item->version, 'reviewer_id' => $this->reviewer->id,
            'assignee_id' => null, 'decision' => 'approve', 'rationale' => null, 'content_hash' => str_repeat('a', 64), 'decided_at' => now(),
        ];
        foreach ([
            ['decision' => 'approved'],
            ['decision' => 'reject'], // no reason
            ['decision' => 'request_changes', 'rationale' => '   '],
            ['decision' => 'assign'], // nobody assigned
            ['assignee_id' => $this->reviewer->id], // an assignee on a decision
            ['content_hash' => str_repeat('A', 64)],
            ['content_hash' => str_repeat('a', 63).'g'],
            ['item_version' => 0],
        ] as $values) {
            $this->assertDatabaseRefuses(fn () => DB::table('verification_reviews')->insert($row($values)), 'Check constraint');
        }
        DB::table('verification_reviews')->insert($row(['decision' => 'reassign', 'assignee_id' => $this->director->id, 'rationale' => 'Valid']));
        $this->assertSame(2, VerificationReview::query()->count());
    }

    public function test_the_content_hash_follows_the_content_only(): void
    {
        $item = $this->draft();
        $claim = $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => $item->version]);
        $hash = IntelligenceContent::hash($item->refresh());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        $update = fn (string $title) => app(IntelligenceItemService::class)->update($this->analyst, $item->refresh(), [
            'title' => $title, 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$this->acme], 'version' => $item->refresh()->version,
        ]);
        $update('Widget relaunch');
        $this->assertNotSame($hash, IntelligenceContent::hash($item->refresh()));
        $update('Widget launch');
        $this->assertSame($hash, IntelligenceContent::hash($item->refresh()), 'the same content hashes the same at another version');

        $this->cite($item->refresh(), $claim, $this->available());
        $cited = IntelligenceContent::hash($item->refresh());
        $this->assertNotSame($hash, $cited);
        $this->claims()->withdraw($this->analyst, $item, $claim->ulid, ['version' => $item->version]);
        $this->assertNotSame($cited, IntelligenceContent::hash($item->refresh()), 'a withdrawn claim is still content');
    }

    /** @param  list<string>  $problems */
    private function expectProblems(IntelligenceItem $item, array $problems): void
    {
        try {
            $this->workflow()->decide($this->reviewer, $item->refresh(), ['decision' => 'approve', 'version' => $item->version]);
            $this->fail('Approved an item whose support the reviewer cannot check.');
        } catch (ValidationException $e) {
            $this->assertSame($problems, $e->errors()['claims'] ?? null);
        }
        $this->assertSame('in_review', $item->refresh()->status);
    }

    private function draft(?int $competitor = null, bool $highImpact = false, string $classification = 'internal'): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => $classification, 'high_impact' => $highImpact,
            'competitors' => [$competitor === null ? $this->acme : (string) DB::table('competitors')->where('id', $competitor)->value('ulid')],
        ]);
    }

    /** A draft with one claim supported by available evidence, submitted. */
    private function submitted(?int $competitor = null, bool $highImpact = false, string $classification = 'internal'): IntelligenceItem
    {
        $item = $this->draft($competitor, $highImpact, $classification);
        $claim = $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => $item->version]);
        $this->cite($item->refresh(), $claim, $this->available());
        $this->workflow()->submit($this->analyst, $item->refresh(), ['version' => $item->version]);

        return $item->refresh();
    }

    /** Submitted, assigned to the reviewer, and under review. */
    private function inReview(): IntelligenceItem
    {
        $item = $this->submitted();
        $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid]);
        $this->workflow()->startReview($this->reviewer, $item);

        return $item->refresh();
    }

    /**
     * A submitted item written straight to the database (for the matrix).
     *
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     */
    private function queued(string $title, string $classification, array $competitors, array $markets): string
    {
        $ulid = (string) Str::ulid();
        $id = DB::table('intelligence_items')->insertGetId([
            'ulid' => $ulid, 'title' => $title, 'summary' => '', 'item_type' => 'news', 'classification' => $classification, 'status' => 'submitted',
            'author_id' => $this->analyst->id, 'owner_id' => $this->analyst->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($competitors as $competitor) {
            DB::table('competitor_intelligence_item')->insert(['intelligence_item_id' => $id, 'competitor_id' => $competitor]);
        }
        foreach ($markets as $market) {
            DB::table('intelligence_item_market')->insert(['intelligence_item_id' => $id, 'market_id' => $market]);
        }

        return $ulid;
    }

    private function cite(IntelligenceItem $item, IntelligenceClaim $claim, EvidenceAsset $asset): void
    {
        $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => $item->version]);
    }

    /** @return array<string, mixed> */
    private function claimFields(): array
    {
        return ['statement' => 'Acme sells the widget', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2];
    }

    private function competitor(string $name): int
    {
        return (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function blob(): int
    {
        return (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
    }

    private function available(): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $this->blob(),
            'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence', 'captured_at' => now()->subDay(),
            'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset;
    }

    private function grantAll(User $user): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
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
