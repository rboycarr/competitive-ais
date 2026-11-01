<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Modules\Identity\Actions\AccessGrantService;
use Modules\Identity\Actions\TeamService;
use Modules\Identity\Models\AccessGrant;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Classification;
use Tests\Support\Access\ProbeRecord;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-15 / ADR-0005 / docs/07 §3: one AccessScope decides visibility for single records, lists and HTTP reads, and
 * all of them agree with the predicate over the whole authorization matrix. Hidden records are 404.
 */
class AccessScopeTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var list<array{user: User, clearance: string, status: string, grant: string}> */
    private array $users;

    /** @var list<array{label: string, classification: string, competitors: list<int>, markets: list<int>}> */
    private array $records;

    /** @var array<string, ProbeRecord> label => record */
    private array $probes = [];

    protected function setUp(): void
    {
        parent::setUp();

        ProbeRecord::createSchema();
        $this->users = AuthorizationMatrix::users();
        $this->records = AuthorizationMatrix::records();
        foreach ($this->records as $spec) {
            $this->probes[$spec['label']] = ProbeRecord::seed($spec['label'], $spec['classification'], $spec['competitors'], $spec['markets']);
        }

        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/_test/probes/{id}', function (Request $request, string $id) {
                $record = ProbeRecord::query()->findOrFail((int) $id);
                app(AccessScope::class)->ensureVisible($request->user() ?? abort(401), $record);

                return $record->label;
            });
            Route::get('/_test/probes', fn (Request $request) => ProbeRecord::query()->visibleTo($request->user() ?? abort(401))->orderBy('id')->pluck('label'));
        });
    }

    public function test_the_matrix_covers_every_combination(): void
    {
        $this->assertCount(4 * 2 * 9, $this->users);
        $this->assertCount(4 * 6, $this->records);

        // Both outcomes occur often, so a predicate that always answers the same cannot pass.
        $visible = 0;
        foreach ($this->users as $user) {
            foreach ($this->records as $record) {
                $visible += (int) AuthorizationMatrix::expected($user, $record);
            }
        }
        $this->assertGreaterThan(100, $visible);
        $this->assertGreaterThan(100, count($this->users) * count($this->records) - $visible);
    }

    public function test_single_record_checks_follow_the_predicate(): void
    {
        $scope = $this->app->make(AccessScope::class);

        AuthorizationMatrix::assertMatrix($this->users, $this->records, fn (User $user, array $record): bool => $scope->allows($user, $this->probes[$record['label']]), 'AccessScope::allows()');
    }

    public function test_lists_follow_the_predicate(): void
    {
        $visible = [];
        foreach ($this->users as $entry) {
            $visible[$entry['user']->id] = ProbeRecord::query()->visibleTo($entry['user'])->pluck('label')->all();
        }

        AuthorizationMatrix::assertMatrix($this->users, $this->records, fn (User $user, array $record): bool => in_array($record['label'], $visible[$user->id], true), 'visibleTo()');
    }

    public function test_http_reads_return_404_for_hidden_records(): void
    {
        // Active users only: inactive sessions end before any read (EnsureActiveAccount, covered in AdministrationTest).
        $users = array_values(array_filter($this->users, fn (array $u): bool => $u['status'] === 'active' && in_array($u['grant'], ['none', 'all:confidential', 'competitor:A:confidential'], true)));

        AuthorizationMatrix::assertMatrix($users, $this->records, function (User $user, array $record): bool {
            $status = $this->actingAs($user)->get('/_test/probes/'.$this->probes[$record['label']]->id)->getStatusCode();
            $this->assertContains($status, [200, 404], "Unexpected status for {$record['label']}");

            return $status === 200;
        }, 'GET /_test/probes/{id}');

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = $this->actingAs($entry['user'])->getJson('/_test/probes')->assertOk()->json();
        }
        AuthorizationMatrix::assertMatrix($users, $this->records, fn (User $user, array $record): bool => in_array($record['label'], $lists[$user->id], true), 'GET /_test/probes');
    }

    public function test_super_admins_get_no_bypass(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $scope = $this->app->make(AccessScope::class);

        $this->assertFalse($scope->allows($admin, $this->probes['restricted/unscoped']));
        $this->assertFalse($scope->allows($admin, $this->probes['public/competitor']));
        $this->assertTrue($scope->allows($admin, $this->probes['confidential/unscoped']));
    }

    public function test_grant_changes_apply_within_the_same_request(): void
    {
        // The grants are cached per request; the grant and revoke actions clear the cache.
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $scope = $this->app->make(AccessScope::class);
        $grants = $this->app->make(AccessGrantService::class);
        $record = $this->probes['internal/competitor'];

        $this->assertFalse($scope->allows($analyst, $record));
        $grant = $grants->grant($director, $analyst, Classification::Internal, false, 'Pricing study', null);
        $this->assertTrue($scope->allows($analyst, $record));
        $grants->revoke($director, $grant);
        $this->assertFalse($scope->allows($analyst, $record));
        $this->assertSame(1, AccessGrant::query()->where('user_id', $analyst->id)->count()); // revoked, not deleted
    }

    public function test_existing_or_conditions_cannot_widen_the_filter(): void
    {
        // TASK-0026: "label = a OR label = b" followed by the filter must stay grouped, called directly or as a scope.
        $user = $this->matrixUser('public', 'active', 'none');
        $scope = $this->app->make(AccessScope::class);
        $hidden = ['restricted/unscoped', 'public/competitor'];
        $direct = $scope->applyTo(ProbeRecord::query()->where('label', $hidden[0])->orWhere('label', $hidden[1])->orWhere('label', 'public/unscoped'), $user);
        $viaScope = ProbeRecord::query()->where('label', $hidden[0])->orWhere('label', 'public/unscoped')->visibleTo($user);

        $this->assertSame(['public/unscoped'], $direct->pluck('label')->all());
        $this->assertSame(['public/unscoped'], $viaScope->pluck('label')->all());
    }

    public function test_team_and_expiry_changes_apply_and_the_cache_lives_one_request(): void
    {
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $member = $this->matrixUser('confidential', 'active', 'team:confidential');
        $scope = $this->app->make(AccessScope::class);
        $record = $this->probes['internal/market'];
        $this->assertTrue($scope->allows($member, $record));

        // Leaving the team withdraws its grants in the same request (TeamService clears the cache).
        $team = Team::query()->whereHas('members', fn ($q) => $q->whereKey($member->id))->sole();
        $this->app->make(TeamService::class)->removeMember($admin, $team, $member);
        $this->assertFalse($scope->allows($member, $record));

        // A change that bypasses the actions is seen by the next request or job (scoped binding), not this one.
        $direct = $this->matrixUser('confidential', 'active', 'all:confidential');
        $this->assertTrue($scope->allows($direct, $record));
        AccessGrant::query()->where('user_id', $direct->id)->update(['expires_at' => now('UTC')->addMinute()]);
        $this->travel(1)->minutes();
        $this->assertTrue($scope->allows($direct, $record), 'cached for this request');
        $this->app->forgetScopedInstances();
        $fresh = $this->app->make(AccessScope::class);
        $this->assertNotSame($scope, $fresh);
        $this->assertFalse($fresh->allows($direct, $record), 'expired at the exact instant, seen by the next request');
    }

    private function matrixUser(string $clearance, string $status, string $grant): User
    {
        foreach ($this->users as $entry) {
            if ([$entry['clearance'], $entry['status'], $entry['grant']] === [$clearance, $status, $grant]) {
                return $entry['user'];
            }
        }
        $this->fail("No matrix user $clearance/$status/$grant.");
    }

    public function test_separate_competitor_grants_together_cover_a_record_about_both(): void
    {
        // TASK-0033: all-of is satisfied by two grants, one per competitor, each at the record's level or above.
        $user = $this->matrixUser('confidential', 'active', 'none');
        $grantor = $this->matrixUser('restricted', 'active', 'none');
        $scope = $this->app->make(AccessScope::class);
        $record = $this->probes['confidential/competitors'];
        $grant = fn (string $letter, string $level) => DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'competitor', 'competitor_id' => AuthorizationMatrix::competitorId($letter),
            'max_classification' => $level, 'granted_by' => $grantor->id, 'reason' => 'Both competitors', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $grant('A', 'confidential');
        $grant('B', 'internal'); // B only up to internal: not enough for a confidential record
        $scope->forget();
        $this->assertFalse($scope->allows($user, $record));
        $this->assertNotContains('confidential/competitors', ProbeRecord::query()->visibleTo($user)->pluck('label')->all());
        $this->assertContains('internal/competitors', ProbeRecord::query()->visibleTo($user)->pluck('label')->all());

        $grant('B', 'confidential');
        $scope->forget();
        $this->assertTrue($scope->allows($user, $record));
        $this->assertContains('confidential/competitors', ProbeRecord::query()->visibleTo($user)->pluck('label')->all());
        $this->assertNotContains('confidential/competitor-and-market', ProbeRecord::query()->visibleTo($user)->pluck('label')->all());
    }

    public function test_unknown_users_and_unprepared_models_are_refused(): void
    {
        $scope = $this->app->make(AccessScope::class);
        $stranger = new GenericUser(['id' => 1, 'clearance' => 'restricted', 'status' => 'active']);

        $this->assertFalse($scope->allows($stranger, $this->probes['public/unscoped']));
        $this->assertSame([], ProbeRecord::query()->visibleTo($stranger)->pluck('label')->all());

        $this->expectException(LogicException::class);
        $scope->applyTo(User::query(), $this->users[0]['user']);
    }
}
