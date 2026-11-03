<?php

namespace Modules\Sources\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourceLifecycleService;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use Modules\Sources\Models\SourcePolicy;
use Modules\Sources\Models\SourceStatusChange;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-31b / docs/04 §2: source.manage holders suspend an approved source (at once), reinstate a suspended one (back to
 * policy review: a director approves a new policy version, PO decision O31) and retire a source (final). Every change
 * has a reason code and a note in the append-only history and is audited; the database holds the transitions.
 */
class SourceLifecycleTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_suspend_reinstate_with_a_new_review_then_retire(): void
    {
        $source = $this->approved('Trade news');
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lifecycleActions', [['action' => 'suspend', 'reasons' => SourceStatusChange::ACTIONS['suspend']['reasons']], ['action' => 'retire', 'reasons' => SourceStatusChange::ACTIONS['retire']['reasons']]])
            ->where('statusChanges', []));

        $this->change($source, 'suspend', 'complaint', '  Synthetic complaint about terms.  ')
            ->assertRedirect("/sources/{$source->ulid}")->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Source Trade news suspended: nobody may use it until a director approves it again.');
        $source->refresh();
        $firstPolicy = $source->current_policy_id;
        $this->assertSame('suspended', $source->status);
        $this->assertNotNull($firstPolicy); // the last approval stays on record; it is not in force while suspended

        $this->change($source, 'reinstate', 'resolved', 'Synthetic: the complaint was resolved.')->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Source Trade news sent for a new policy review.');
        $this->assertSame('policy_review', $source->refresh()->status);
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canDecide', true)->where('basedOnVersion', 1)->where('lifecycleActions.0.action', 'retire')->has('lifecycleActions', 1));
        app(SourcePolicyService::class)->decide($this->director, $source, $this->decision(['based_on_version' => 1]));
        $source->refresh();
        $this->assertSame('approved', $source->status);
        $this->assertSame(SourcePolicy::query()->where('version', 2)->value('id'), $source->current_policy_id);
        $this->assertNotSame($firstPolicy, $source->current_policy_id);

        $this->change($source, 'retire', 'no_longer_needed', 'Synthetic: replaced by a licensed feed.')->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Source Trade news retired.');
        $this->assertSame('retired', $source->refresh()->status);

        $history = SourceStatusChange::query()->orderBy('id')->get();
        $this->assertSame(
            [['suspend', 'approved', 'suspended', 'complaint', 'Synthetic complaint about terms.'], ['reinstate', 'suspended', 'policy_review', 'resolved', 'Synthetic: the complaint was resolved.'],
                ['retire', 'approved', 'retired', 'no_longer_needed', 'Synthetic: replaced by a licensed feed.']],
            $history->map(fn (SourceStatusChange $c) => [$c->action, $c->from_status, $c->to_status, $c->reason, $c->note])->all(),
        );
        $this->assertSame([$this->director->id], $history->pluck('changed_by')->unique()->values()->all());

        $events = DB::table('audit_events')->whereIn('action', ['source.suspended', 'source.reinstated', 'source.retired'])->orderBy('id')->get();
        $this->assertSame(['source.suspended', 'source.reinstated', 'source.retired'], $events->pluck('action')->all());
        $this->assertSame([$source->ulid], $events->pluck('subject_ulid')->unique()->values()->all());
        $this->assertEquals(['from_status' => 'approved', 'reason' => 'complaint'], json_decode((string) DB::table('audit_events')->where('action', 'source.suspended')->value('context'), true));
        $this->assertSame(0, DB::table('audit_events')->where('context', 'like', '%Synthetic%')->count(), 'notes stay out of the audit context');

        // Retired is final: nothing more to change, submit or decide.
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lifecycleActions', [])->where('canSubmit', false)->where('canDecide', false)->has('statusChanges', 3)
            ->where('statusChanges.0.action', 'retire')->where('statusChanges.0.changedBy', 'Test director@example.test'));
        foreach (['suspend' => 'complaint', 'reinstate' => 'resolved', 'retire' => 'other'] as $action => $reason) {
            $this->change($source, $action, $reason, 'Synthetic.')->assertSessionHasErrors('action');
        }
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertSessionHasErrors('source');
        $this->assertSame(3, SourceStatusChange::query()->count());
    }

    public function test_each_change_starts_only_from_its_statuses(): void
    {
        $proposed = $this->propose('Proposed feed');
        $this->change($proposed, 'suspend', 'complaint', 'Synthetic.')->assertSessionHasErrors(['action' => 'Only an approved source can be suspended.']);
        $this->change($proposed, 'reinstate', 'resolved', 'Synthetic.')->assertSessionHasErrors(['action' => 'Only a suspended source can be reinstated.']);
        $approved = $this->approved('Approved feed');
        $this->change($approved, 'reinstate', 'resolved', 'Synthetic.')->assertSessionHasErrors('action');
        $this->assertSame(0, SourceStatusChange::query()->count());

        // An abandoned proposal or a source waiting for review can be retired.
        $this->change($proposed, 'retire', 'no_longer_needed', 'Synthetic: abandoned.')->assertSessionHasNoErrors();
        $review = $this->propose('Queued feed');
        app(SourcePolicyService::class)->submit($this->analyst, $review);
        $this->change($review, 'retire', 'other', 'Synthetic: withdrawn.')->assertSessionHasNoErrors();
        $this->assertSame(['retired', 'retired', 'approved'], [$proposed->refresh()->status, $review->refresh()->status, $approved->refresh()->status]);

        // A source retired while awaiting review can be neither decided nor submitted again (TASK-0061).
        $this->actingAs($this->director)->post("/sources/{$review->ulid}/policies", $this->decision())->assertSessionHasErrors('decision');
        $this->actingAs($this->analyst)->post("/sources/{$review->ulid}/submit")->assertSessionHasErrors('source');
        $this->assertSame([0, 'retired'], [SourcePolicy::query()->where('source_id', $review->id)->count(), $review->refresh()->status]);
    }

    public function test_a_rejected_re_review_keeps_the_old_approval_on_record_but_not_in_force(): void
    {
        $source = $this->approved('Trade news');
        $first = $source->current_policy_id;
        $this->change($source, 'suspend', 'terms_changed', 'Synthetic: the terms changed.');
        $this->change($source, 'reinstate', 'terms_changed', 'Synthetic: new terms to review.');
        app(SourcePolicyService::class)->decide($this->director, $source, $this->decision(['decision' => 'rejected', 'based_on_version' => 1, 'notes' => 'Synthetic: the new terms forbid our use.']));

        $source->refresh();
        $this->assertSame(['proposed', $first], [$source->status, $source->current_policy_id]);
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.status', 'proposed')->where('source.currentPolicyVersion', 1)
            ->where('lifecycleActions', [['action' => 'retire', 'reasons' => SourceStatusChange::ACTIONS['retire']['reasons']]]));
        // Only approved sources may be used (P1-37 checks the status); suspending needs an approved source.
        $this->change($source, 'suspend', 'complaint', 'Synthetic.')->assertSessionHasErrors('action');
    }

    public function test_only_source_managers_who_can_see_the_source_change_it(): void
    {
        $source = $this->approved('Trade news');
        $collector = $this->createUser('collector@example.test', 'data_collector');
        $this->change($source, 'suspend', 'complaint', 'Synthetic.', $this->analyst)->assertForbidden();
        $this->change($source, 'suspend', 'complaint', 'Synthetic.', $collector)->assertForbidden();
        $this->actingAs($this->analyst)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('lifecycleActions', []));
        $this->assertThrows(fn () => app(SourceLifecycleService::class)->change($this->analyst, $source, 'suspend', 'complaint', 'Synthetic.'), AuthorizationException::class);

        $hidden = app(SourceService::class)->propose($this->analyst, ['name' => 'Confidential feed', 'source_type' => 'rss', 'classification' => 'confidential']);
        $internal = $this->enableTwoFactor($this->createUser('internal-director@example.test', 'ci_director'));
        $this->change($hidden, 'retire', 'other', 'Synthetic.', $internal)->assertNotFound();
        $this->assertSame(0, SourceStatusChange::query()->count());
        $this->assertSame(['approved', 'proposed'], [$source->refresh()->status, $hidden->refresh()->status]);
    }

    public function test_changes_need_an_allowed_reason_and_a_note(): void
    {
        $source = $this->approved('Trade news');
        foreach ([
            ['reason', ['action' => 'suspend', 'reason' => 'resolved', 'note' => 'Synthetic.']],
            ['reason', ['action' => 'suspend', 'reason' => 'whim', 'note' => 'Synthetic.']],
            ['note', ['action' => 'suspend', 'reason' => 'complaint', 'note' => '   ']],
            ['note', ['action' => 'suspend', 'reason' => 'complaint', 'note' => str_repeat('x', 10001)]],
            ['action', ['action' => 'delete', 'reason' => 'other', 'note' => 'Synthetic.']],
            ['note', ['action' => 'suspend', 'reason' => 'complaint', 'note' => ['Synthetic.']]],
            ['reason', ['action' => 'suspend', 'reason' => ['complaint'], 'note' => 'Synthetic.']],
        ] as [$field, $form]) {
            $this->actingAs($this->director)->post("/sources/{$source->ulid}/status", $form)->assertSessionHasErrors($field);
        }
        // The service holds the rules for direct callers, with validation errors rather than type errors.
        foreach ([['action', null, 'complaint', 'x'], ['action', ['suspend'], 'complaint', 'x'], ['reason', 'suspend', 1, 'x'], ['note', 'suspend', 'complaint', ['x']], ['note', 'suspend', 'complaint', null]] as [$field, $action, $reason, $note]) {
            try {
                app(SourceLifecycleService::class)->change($this->director, $source, $action, $reason, $note);
                $this->fail("Expected a validation error for $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame(0, SourceStatusChange::query()->count());
        $this->assertSame('approved', $source->refresh()->status);

        // Exactly the limit is accepted.
        $this->change($source, 'suspend', 'complaint', str_repeat('x', 10000))->assertSessionHasNoErrors();
        $this->assertSame(10000, mb_strlen(SourceStatusChange::query()->sole()->note));
    }

    public function test_the_database_holds_the_lifecycle_rules(): void
    {
        $source = $this->approved('Trade news');
        $row = fn (array $overrides = []): array => $overrides + [
            'source_id' => $source->id, 'action' => 'suspend', 'from_status' => 'approved', 'to_status' => 'suspended', 'reason' => 'complaint',
            'note' => 'Synthetic.', 'changed_by' => $this->director->id, 'changed_at' => now(),
        ];
        foreach ([
            ['action' => 'delete'], ['to_status' => 'retired'], ['from_status' => 'proposed'], ['reason' => 'resolved'], ['note' => ' '],
            ['action' => 'reinstate', 'from_status' => 'suspended', 'to_status' => 'approved', 'reason' => 'resolved'],
            ['action' => 'retire', 'from_status' => 'retired', 'to_status' => 'retired', 'reason' => 'other'],
        ] as $overrides) {
            $this->assertDatabaseError(3819, fn () => DB::table('source_status_changes')->insert($row($overrides)), (string) json_encode($overrides));
        }
        $this->assertDatabaseError(1452, fn () => DB::table('source_status_changes')->insert($row(['source_id' => 999999])), 'unknown source');

        $id = DB::table('source_status_changes')->insertGetId($row());
        $this->assertDatabaseError(1142, fn () => DB::table('source_status_changes')->where('id', $id)->update(['note' => 'Changed.']), 'update');
        $this->assertDatabaseError(1142, fn () => DB::table('source_status_changes')->where('id', $id)->delete(), 'delete');
    }

    public function test_rollback_is_refused_while_changes_exist(): void
    {
        $source = $this->approved('Trade news');
        $this->change($source, 'suspend', 'complaint', 'Synthetic.');

        $migration = require base_path('modules/Sources/database/migrations/2026_11_17_000100_create_source_status_changes_table.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'Source status changes exist');
        $this->assertSame(1, SourceStatusChange::query()->count());

        // Rolling back the DATETIME conversion keeps DATETIME(6): TIMESTAMP never comes back (TASK-0062).
        $type = fn (): ?string => DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source_policies' AND COLUMN_NAME = 'decided_at'")?->COLUMN_TYPE;
        $before = $type();
        (require base_path('modules/Sources/database/migrations/2026_11_17_000200_use_datetime_for_source_policy_decisions.php'))->down();
        $this->assertSame(['datetime(6)', 'datetime(6)'], [$before, $type()]);
    }

    /** @return TestResponse<Response> */
    private function change(Source $source, string $action, string $reason, string $note, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->director)->from("/sources/{$source->ulid}")
            ->post("/sources/{$source->ulid}/status", ['action' => $action, 'reason' => $reason, 'note' => $note]);
    }

    private function propose(string $name): Source
    {
        return app(SourceService::class)->propose($this->analyst, ['name' => $name, 'source_type' => 'website', 'classification' => 'internal']);
    }

    private function approved(string $name): Source
    {
        $source = $this->propose($name);
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        app(SourcePolicyService::class)->decide($this->director, $source, $this->decision());

        return $source->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{decision: string, based_on_version: int, robots_decision: string, contains_personal_data: bool}
     */
    private function decision(array $overrides = []): array
    {
        /** @var array{decision: string, based_on_version: int, robots_decision: string, contains_personal_data: bool} $form */
        $form = $overrides + [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ];

        return $form;
    }

    private function assertDatabaseError(int $code, callable $statement, string $case): void
    {
        try {
            $statement();
            $this->fail("Expected database error $code for: $case");
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[1] ?? null, "$case: {$e->getMessage()}");
        }
    }
}
