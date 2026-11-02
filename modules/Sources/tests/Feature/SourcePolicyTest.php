<?php

namespace Modules\Sources\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use Modules\Sources\Models\SourcePolicy;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-31a / docs/04 §2 / docs/02 §3.4: the owner or proposer submits a source for policy review; a director (source.approve,
 * two-factor) who did not propose it decides; each decision is a new, unchangeable policy version; an approval puts the
 * policy in force, a rejection or request for information returns the source to its proposer. The database holds the
 * same rules.
 */
class SourcePolicyTest extends TestCase
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

    public function test_the_proposer_submits_and_a_director_approves_with_a_new_policy_version(): void
    {
        $source = $this->propose('Trade news');
        $this->actingAs($this->analyst)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSubmit', true)->where('canDecide', false)->where('policies', [])->where('source.currentPolicyVersion', null));

        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertRedirect("/sources/{$source->ulid}")->assertSessionHasNoErrors();
        $this->assertSame('policy_review', $source->fresh()?->status);
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSubmit', false)->where('canDecide', true)->where('basedOnVersion', 0)->where('robotsBound', false));

        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved', [
            'terms_url' => 'https://news.example/terms', 'license' => 'Synthetic licence', 'allowed_cadence' => 'daily',
            'rate_budget_per_hour' => 60, 'retention_days' => 365, 'contains_personal_data' => true, 'personal_data_basis' => 'Synthetic basis.',
        ]))->assertRedirect("/sources/{$source->ulid}")->assertSessionHasNoErrors()->assertSessionHas('status', 'Policy version 1: Trade news approved.');

        $policy = SourcePolicy::query()->sole();
        $source->refresh();
        $this->assertSame(['approved', $policy->id], [$source->status, $source->current_policy_id]);
        $this->assertSame(
            [1, 'approved', $this->analyst->id, $this->director->id, 'https://news.example/terms', 'not_applicable', 60, 365, true, 'Synthetic basis.', null],
            [$policy->version, $policy->decision, $policy->proposed_by, $policy->decided_by, $policy->terms_url, $policy->robots_decision,
                $policy->rate_budget_per_hour, $policy->retention_days, $policy->contains_personal_data, $policy->personal_data_basis, $policy->notes],
        );

        $events = DB::table('audit_events')->whereIn('action', ['source.submitted', 'source.policy_decided'])->orderBy('id')->get();
        $this->assertSame(['source.submitted', 'source.policy_decided'], $events->pluck('action')->all());
        $this->assertSame([$this->analyst->id, $this->director->id], $events->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$source->ulid, $source->ulid], $events->pluck('subject_ulid')->all());
        $this->assertSame([null], $events->where('action', 'source.submitted')->pluck('context')->all());
        // MySQL's JSON type orders keys itself, so compare as a map.
        $this->assertEquals(['decision' => 'approved', 'version' => 1, 'robots_decision' => 'not_applicable', 'contains_personal_data' => true],
            json_decode((string) DB::table('audit_events')->where('action', 'source.policy_decided')->value('context'), true));

        $this->actingAs($this->analyst)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.status', 'approved')->where('source.currentPolicyVersion', 1)->where('canSubmit', false)->where('canDecide', false)
            ->has('policies', 1)->where('policies.0.decision', 'approved')->where('policies.0.decidedBy', 'Test director@example.test')
            ->where('policies.0.termsUrl', 'https://news.example/terms')->where('policies.0.containsPersonalData', true));
    }

    public function test_rejections_and_requests_for_information_return_the_source_to_its_proposer(): void
    {
        $source = $this->propose('Trade news');
        $steps = [['needs_info', 0, 'returned to its proposer for more information'], ['rejected', 1, 'rejected and returned to its proposer'], ['approved', 2, 'approved']];
        foreach ($steps as [$decision, $basedOn, $outcome]) {
            $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertSessionHasNoErrors();
            $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision($decision, ['based_on_version' => $basedOn]))
                ->assertSessionHasNoErrors()->assertSessionHas('status', 'Policy version '.($basedOn + 1).": Trade news $outcome.");
            $this->assertSame($decision === 'approved' ? 'approved' : 'proposed', $source->fresh()?->status);
        }

        $policies = SourcePolicy::query()->orderBy('version')->get();
        $this->assertSame([[1, 'needs_info'], [2, 'rejected'], [3, 'approved']], $policies->map(fn (SourcePolicy $p) => [$p->version, $p->decision])->all());
        $this->assertSame(SourcePolicy::query()->where('version', 3)->value('id'), $source->refresh()->current_policy_id);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'source.policy_decided')->count());

        // Decided: nothing to submit or decide until P1-31b's lifecycle steps.
        $this->actingAs($this->analyst)->from("/sources/{$source->ulid}")->post("/sources/{$source->ulid}/submit")->assertSessionHasErrors('source');
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('rejected', ['based_on_version' => 3]))->assertSessionHasErrors('decision');
        $this->assertSame(3, SourcePolicy::query()->count());
    }

    public function test_only_the_owner_or_proposer_submits_and_only_another_director_decides(): void
    {
        $collector = $this->createUser('collector@example.test', 'data_collector'); // source.view only
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $owner = $this->createUser('owner@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $unenrolled = $this->createUser('unenrolled@example.test', 'ci_director', clearance: Classification::Confidential); // no two-factor
        $source = $this->propose('Trade news', ['owner' => $owner->ulid]);

        $this->actingAs($collector)->post("/sources/{$source->ulid}/submit")->assertForbidden();
        $this->actingAs($other)->post("/sources/{$source->ulid}/submit")->assertForbidden();
        $this->actingAs($other)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canSubmit', false));
        $this->actingAs($owner)->post("/sources/{$source->ulid}/submit")->assertSessionHasNoErrors();
        $this->assertSame('policy_review', $source->fresh()?->status);

        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/policies", $this->decision('approved'))->assertForbidden();
        // A director without two-factor authentication is sent to enroll first (EnsureMfaEnrolled), and the service refuses.
        $this->actingAs($unenrolled)->post("/sources/{$source->ulid}/policies", $this->decision('approved'))->assertRedirect('/user/security');
        $this->assertThrows(fn () => app(SourcePolicyService::class)->decide($unenrolled, $source, $this->decision('approved')), AuthorizationException::class);
        $this->assertSame(0, SourcePolicy::query()->count());

        // A director who proposed the source cannot decide it, by any route.
        $own = app(SourceService::class)->propose($this->director, ['name' => 'Director feed', 'source_type' => 'rss', 'classification' => 'internal']);
        $this->actingAs($this->director)->post("/sources/{$own->ulid}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->director)->get("/sources/{$own->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canDecide', false)->where('decidingOwnProposal', true));
        $this->actingAs($this->director)->post("/sources/{$own->ulid}/policies", $this->decision('approved'))->assertForbidden();
        $this->assertSame(0, SourcePolicy::query()->count());

        // The services check the actor themselves.
        $this->assertThrows(fn () => app(SourcePolicyService::class)->decide($this->analyst, $source, $this->decision('approved')), AuthorizationException::class);
        $this->assertThrows(fn () => app(SourcePolicyService::class)->decide($this->director, $own, $this->decision('approved')), AuthorizationException::class);
        $this->assertThrows(fn () => app(SourcePolicyService::class)->submit($collector, $source), AuthorizationException::class);
        $this->assertSame(0, SourcePolicy::query()->count());
    }

    public function test_hidden_sources_are_not_found(): void
    {
        $source = app(SourceService::class)->propose($this->analyst, ['name' => 'Confidential feed', 'source_type' => 'rss', 'classification' => 'confidential']);
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertSessionHasNoErrors();
        $internal = $this->enableTwoFactor($this->createUser('internal-director@example.test', 'ci_director'));

        $this->actingAs($internal)->post("/sources/{$source->ulid}/policies", $this->decision('approved'))->assertNotFound();
        $this->assertSame(0, SourcePolicy::query()->count());
        $this->assertSame('policy_review', $source->fresh()?->status);

        // Once decided, the history is shown only with its source (TASK-0059).
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved'))->assertSessionHasNoErrors();
        $this->actingAs($internal)->get("/sources/{$source->ulid}")->assertNotFound();
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->has('policies', 1));
    }

    public function test_direct_calls_with_malformed_input_get_validation_errors(): void
    {
        $source = $this->propose('Trade news');
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        $valid = $this->decision('approved');
        $without = fn (string $key): array => array_diff_key($valid, [$key => true]);

        foreach ([
            ['contains_personal_data', $without('contains_personal_data')],
            ['contains_personal_data', ['contains_personal_data' => 'yes'] + $valid],
            ['contains_personal_data', ['contains_personal_data' => 1] + $valid],
            ['decision', $without('decision')],
            ['decision', $without('based_on_version')],
            ['decision', ['based_on_version' => '0'] + $valid],
            ['decision', ['based_on_version' => -1] + $valid],
            ['robots_decision', $without('robots_decision')],
            ['notes', ['notes' => ['array']] + $valid],
            ['rate_budget_per_hour', ['rate_budget_per_hour' => '60'] + $valid],
        ] as [$field, $data]) {
            try {
                // @phpstan-ignore argument.type (deliberately malformed)
                app(SourcePolicyService::class)->decide($this->director, $source, $data);
                $this->fail("Expected a validation error for $field: ".json_encode($data));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), (string) json_encode($data));
            }
        }
        $this->assertSame(0, SourcePolicy::query()->count());
    }

    public function test_a_decision_needs_its_review_and_a_current_view(): void
    {
        $source = $this->propose('Trade news');
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved'))->assertSessionHasErrors('decision');

        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit")->assertSessionHasErrors('source');
        // A review that changed since the director loaded it (here: a version they have not seen).
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved', ['based_on_version' => 1]))
            ->assertSessionHasErrors(['decision' => "This source's policy changed while you were reviewing it; reload the page and review it again."]);
        $this->assertSame(0, SourcePolicy::query()->count());
        $this->assertSame('policy_review', $source->fresh()?->status);
    }

    public function test_decisions_are_validated(): void
    {
        $source = $this->propose('Trade news');
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit");
        $tomorrow = now('UTC')->addDay()->format('Y-m-d');

        foreach ([
            'terms_summary' => $this->decision('approved', ['terms_summary' => '  ']),
            'terms_reviewed_on' => $this->decision('approved', ['terms_reviewed_on' => $tomorrow]),
            'notes' => $this->decision('rejected', ['notes' => ' ']),
            'personal_data_basis' => $this->decision('approved', ['contains_personal_data' => true]),
            'terms_url' => $this->decision('approved', ['terms_url' => 'https://news.example/terms?key=synthetic']),
            'rate_budget_per_hour' => $this->decision('approved', ['rate_budget_per_hour' => 0]),
            'retention_days' => $this->decision('approved', ['retention_days' => 36501]),
            'robots_decision' => $this->decision('approved', ['robots_decision' => 'maybe']),
            'decision' => $this->decision('approved', ['decision' => 'final']),
        ] as $field => $form) {
            $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $form)->assertSessionHasErrors($field);
        }
        // The service holds the rules without the form request.
        foreach ([['terms_url', ['terms_url' => 'https://user:pw@news.example/terms']], ['terms_reviewed_on', ['terms_reviewed_on' => '2026-02-30']], ['terms_reviewed_on', ['terms_reviewed_on' => null]]] as [$field, $override]) {
            try {
                app(SourcePolicyService::class)->decide($this->director, $source, $this->decision('approved', $override));
                $this->fail("Expected a validation error for $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame(0, SourcePolicy::query()->count());

        // Without personal data, a basis given by mistake is not stored.
        app(SourcePolicyService::class)->decide($this->director, $source, $this->decision('approved', ['personal_data_basis' => 'Left over.']));
        $this->assertNull(SourcePolicy::query()->sole()->personal_data_basis);
    }

    public function test_pages_read_like_a_visitor_only_where_robots_allow_it(): void
    {
        $source = $this->source('Crawled site', method: 'http_fetch', status: 'policy_review');
        $this->actingAs($this->director)->get("/sources/{$source->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('robotsBound', true));

        foreach (['not_reviewed', 'disallowed', 'not_applicable'] as $robots) {
            $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved', ['robots_decision' => $robots]))->assertSessionHasErrors('robots_decision');
        }
        // A rejection can record any robots decision.
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('rejected', ['robots_decision' => 'disallowed']))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit");
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved', ['robots_decision' => 'allowed', 'based_on_version' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('approved', $source->fresh()?->status);
    }

    public function test_the_database_holds_the_review_rules(): void
    {
        // The policy in force is held by a foreign key that includes a STORED generated column (MySQL allows foreign keys
        // on stored, not virtual, generated columns); the migration created it as designed (TASK-0059 #1).
        $this->assertSame(
            [['current_policy_id', 'id'], ['id', 'source_id'], ['current_policy_decision', 'decision']],
            array_map(fn (object $c): array => array_values((array) $c), DB::select(
                "SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'sources' AND CONSTRAINT_NAME = 'sources_current_policy_foreign' AND REFERENCED_TABLE_NAME = 'source_policies' ORDER BY ORDINAL_POSITION",
            )),
        );
        $this->assertSame('STORED GENERATED', DB::selectOne(
            "SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sources' AND COLUMN_NAME = 'current_policy_decision'",
        )?->EXTRA);

        $source = $this->source('Probe', status: 'policy_review');
        $other = $this->source('Other probe', status: 'policy_review');
        $row = fn (array $overrides = []): array => $overrides + [
            'source_id' => $source->id, 'version' => 1, 'proposed_by' => $this->analyst->id, 'decision' => 'approved',
            'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => '2026-11-01', 'robots_decision' => 'not_applicable',
            'contains_personal_data' => false, 'decided_by' => $this->director->id, 'decided_at' => now(),
        ];

        foreach ([
            3819 => [
                ['decided_by' => $this->analyst->id], ['decision' => 'final'], ['robots_decision' => 'maybe'], ['version' => 0],
                ['terms_summary' => ' '], ['terms_reviewed_on' => null], ['decision' => 'rejected'], ['decision' => 'needs_info', 'notes' => ' '],
                ['contains_personal_data' => true], ['terms_url' => 'https://user@news.example/'], ['terms_url' => 'https://news.example/?key=x'],
                ['terms_url' => 'ftp://news.example/'], ['rate_budget_per_hour' => 0], ['retention_days' => 0],
            ],
            1452 => [['proposed_by' => $this->director->id, 'decided_by' => $this->analyst->id], ['source_id' => 999999]],
        ] as $code => $cases) {
            foreach ($cases as $overrides) {
                $this->assertDatabaseError($code, fn () => DB::table('source_policies')->insert($row($overrides)), json_encode($overrides) ?: '');
            }
        }

        $approved = DB::table('source_policies')->insertGetId($row());
        $rejected = DB::table('source_policies')->insertGetId($row(['version' => 2, 'decision' => 'rejected', 'notes' => 'Synthetic reason.']));
        $foreign = DB::table('source_policies')->insertGetId($row(['source_id' => $other->id]));
        $this->assertDatabaseError(1062, fn () => DB::table('source_policies')->insert($row()), 'duplicate version');

        // The policy in force: an approval of the same source; approved and suspended sources need one.
        $this->assertDatabaseError(3819, fn () => DB::table('sources')->where('id', $source->id)->update(['status' => 'approved']), 'approved without a policy');
        $this->assertDatabaseError(1452, fn () => DB::table('sources')->where('id', $source->id)->update(['current_policy_id' => $rejected]), 'a rejection in force');
        $this->assertDatabaseError(1452, fn () => DB::table('sources')->where('id', $source->id)->update(['current_policy_id' => $foreign]), "another source's policy");
        $this->assertDatabaseError(3105, fn () => DB::table('sources')->where('id', $source->id)->update(['current_policy_decision' => 'rejected']), 'the generated decision');
        DB::table('sources')->where('id', $source->id)->update(['status' => 'approved', 'current_policy_id' => $approved]);
        $this->assertDatabaseError(3819, fn () => DB::table('sources')->where('id', $source->id)->update(['status' => 'suspended', 'current_policy_id' => null]), 'suspended without a policy');

        // Decisions are append-only, and the proposer they were checked against is fixed.
        $this->assertDatabaseError(1142, fn () => DB::table('source_policies')->where('id', $approved)->update(['decision' => 'rejected']), 'update');
        $this->assertDatabaseError(1142, fn () => DB::table('source_policies')->where('id', $rejected)->delete(), 'delete');
        $this->assertDatabaseError(1451, fn () => DB::table('sources')->where('id', $source->id)->update(['proposed_by' => $this->director->id]), 'proposer change');
    }

    public function test_rollback_is_refused_while_decisions_exist(): void
    {
        $source = $this->propose('Trade news');
        $this->actingAs($this->analyst)->post("/sources/{$source->ulid}/submit");
        $this->actingAs($this->director)->post("/sources/{$source->ulid}/policies", $this->decision('approved'));

        $migration = require base_path('modules/Sources/database/migrations/2026_11_16_000100_create_source_policies_table.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'Source policy decisions exist');
        $this->assertSame(1, SourcePolicy::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{decision: string, based_on_version: int, terms_url?: ?string, terms_summary?: ?string, terms_reviewed_on?: ?string, robots_decision: string, license?: ?string, allowed_cadence?: ?string, rate_budget_per_hour?: ?int, retention_days?: ?int, contains_personal_data: bool, personal_data_basis?: ?string, notes?: ?string}
     */
    private function decision(string $decision, array $overrides = []): array
    {
        /** @var array{decision: string, based_on_version: int, robots_decision: string, contains_personal_data: bool} $form */
        $form = $overrides + [
            'decision' => $decision, 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms: manual use allowed.',
            'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'), 'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
            'notes' => $decision === 'approved' ? null : 'Synthetic reason.',
        ];

        return $form;
    }

    /** @param  array<string, mixed>  $data */
    private function propose(string $name, array $data = []): Source
    {
        return app(SourceService::class)->propose($this->analyst, $data + ['name' => $name, 'source_type' => 'website', 'classification' => 'internal']);
    }

    private function source(string $name, string $method = 'manual', string $status = 'proposed'): Source
    {
        $source = new Source;
        $source->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'source_type' => 'website', 'base_url' => 'https://news.example/', 'host' => 'news.example',
            'method' => $method, 'status' => $status, 'default_reliability' => 'F', 'owner_id' => $this->analyst->id, 'proposed_by' => $this->analyst->id,
            'classification' => Classification::Internal,
        ])->save();

        return $source;
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
