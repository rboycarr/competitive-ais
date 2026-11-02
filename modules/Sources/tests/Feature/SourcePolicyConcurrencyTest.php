<?php

namespace Modules\Sources\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\User;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\SourcePolicy;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-31a: directors deciding the same review at the same moment (separate processes and connections, Concurrently)
 * record exactly one decision; the others are told the source is no longer awaiting review, never a database error.
 * Data is committed, so the class uses DatabaseMigrations; tearDown removes the governance rows (the app account
 * cannot, and the migrations refuse to roll back while they exist).
 */
class SourcePolicyConcurrencyTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const ROUNDS = 3;

    protected function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        $migrator->table('sources')->update(['current_policy_id' => null, 'status' => 'proposed']);
        $migrator->table('source_policies')->delete();
        $migrator->table('sources')->delete();
        DB::disconnect('migrator');

        parent::tearDown();
    }

    public function test_one_of_several_parallel_decisions_is_recorded(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $directors = array_map(fn (int $i): User => $this->enableTwoFactor($this->createUser("director$i@example.test", 'ci_director')), range(1, 4));
        $decisions = ['approved', 'rejected', 'needs_info', 'approved'];

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $source = app(SourceService::class)->propose($analyst, ['name' => "Feed $round", 'source_type' => 'rss', 'classification' => 'internal']);
            app(SourcePolicyService::class)->submit($analyst, $source);

            $results = Concurrently::run(array_map(fn (User $director, string $decision) => fn () => app(SourcePolicyService::class)->decide($director, $source, [
                'decision' => $decision, 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => '2026-11-01',
                'robots_decision' => 'not_applicable', 'contains_personal_data' => false, 'notes' => 'Synthetic reason.',
            ]), $directors, $decisions));

            $outcomes = array_column($results, 'outcome');
            $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), "round $round: ".json_encode($results));
            $this->assertSame(3, count(array_keys($outcomes, ValidationException::class, true)), "round $round: ".json_encode($results));

            // The stored decision is the winner's, and the source follows it.
            $winner = array_search('ok', $outcomes, true);
            $policy = SourcePolicy::query()->where('source_id', $source->id)->sole();
            $this->assertSame([1, $decisions[$winner], $directors[$winner]->id], [$policy->version, $policy->decision, $policy->decided_by]);
            $source->refresh();
            $this->assertSame($policy->decision === 'approved' ? ['approved', $policy->id] : ['proposed', null], [$source->status, $source->current_policy_id]);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'source.policy_decided')->where('subject_ulid', $source->ulid)->count());
        }
    }
}
