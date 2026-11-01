<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use PHPUnit\Framework\Assert;

/**
 * The shared authorization matrix (docs/07 §3, ADR-0005, P1-15): user clearance × account status × access grant ×
 * record classification × record scopes. Every module that shows records runs its read paths (single record, list,
 * download, export, search…) through assertMatrix(), so all of them agree with the one predicate.
 *
 * The expected result comes from expected(), written directly from the docs/07 predicate and deliberately not from
 * AccessScope's code, so a mistake in the implementation shows up as a mismatch.
 *
 * Usage: create the users with users(), create one record per records() spec in the module's own table, then call
 * assertMatrix() with a callback that answers "can this user see this record?" through the path under test.
 */
final class AuthorizationMatrix
{
    public const CLEARANCES = ['public', 'internal', 'confidential', 'restricted'];

    public const STATUSES = ['active', 'suspended'];

    /** Grant variants: what the user holds (kind:classification). */
    public const GRANTS = ['none', 'all:internal', 'all:confidential', 'all:restricted', 'expired:restricted', 'revoked:restricted', 'team:confidential'];

    /** Record scopes: competitor and market IDs (placeholders until the registry exists). */
    public const SCOPES = ['unscoped' => [[], []], 'competitor' => [[1], []], 'market' => [[], [1]], 'both' => [[1, 2], [1]]];

    private const RANK = ['public' => 0, 'internal' => 1, 'confidential' => 2, 'restricted' => 3];

    /**
     * One user per combination, with the grant in the database.
     *
     * @return list<array{user: User, clearance: string, status: string, grant: string}>
     */
    public static function users(): array
    {
        $grantor = self::makeUser('matrix-grantor', 'restricted', 'active');
        $users = [];
        foreach (self::CLEARANCES as $clearance) {
            foreach (self::STATUSES as $status) {
                foreach (self::GRANTS as $grant) {
                    $user = self::makeUser("matrix-$clearance-$status-".str_replace(':', '-', $grant), $clearance, $status);
                    self::grant($user, $grant, $grantor);
                    $users[] = ['user' => $user, 'clearance' => $clearance, 'status' => $status, 'grant' => $grant];
                }
            }
        }

        return $users;
    }

    /** @return list<array{label: string, classification: string, competitors: list<int>, markets: list<int>}> */
    public static function records(): array
    {
        $records = [];
        foreach (self::CLEARANCES as $classification) {
            foreach (self::SCOPES as $scope => [$competitors, $markets]) {
                $records[] = ['label' => "$classification/$scope", 'classification' => $classification, 'competitors' => $competitors, 'markets' => $markets];
            }
        }

        return $records;
    }

    /**
     * docs/07 §3, as written: active account, classification within clearance, restricted only with a covering
     * grant, scoped records only with a covering grant (all-of; only scope `all` exists before the registry).
     *
     * @param  array{clearance: string, status: string, grant: string}  $user
     * @param  array{classification: string, competitors: list<int>, markets: list<int>}  $record
     */
    public static function expected(array $user, array $record): bool
    {
        if ($user['status'] !== 'active' || self::RANK[$record['classification']] > self::RANK[$user['clearance']]) {
            return false;
        }

        [$kind, $level] = explode(':', $user['grant']) + [1 => null];
        $covered = in_array($kind, ['all', 'team'], true) && $level !== null && self::RANK[$level] >= self::RANK[$record['classification']];
        $scoped = $record['competitors'] !== [] || $record['markets'] !== [];

        return ($record['classification'] !== 'restricted' || $covered) && (! $scoped || $covered);
    }

    /**
     * @param  list<array{user: User, clearance: string, status: string, grant: string}>  $users
     * @param  list<array{label: string, classification: string, competitors: list<int>, markets: list<int>}>  $records
     * @param  callable(User, array{label: string, classification: string, competitors: list<int>, markets: list<int>}): bool  $sees
     */
    public static function assertMatrix(array $users, array $records, callable $sees, string $path): void
    {
        $mismatches = [];
        foreach ($users as $user) {
            foreach ($records as $record) {
                $want = self::expected($user, $record);
                if ($sees($user['user'], $record) !== $want) {
                    $mismatches[] = sprintf('%s/%s/%s %s %s', $user['clearance'], $user['status'], $user['grant'], $want ? 'should see' : 'must not see', $record['label']);
                }
            }
        }

        Assert::assertSame([], $mismatches, "$path disagrees with the docs/07 predicate in ".count($mismatches).' cases.');
    }

    private static function makeUser(string $name, string $clearance, string $status): User
    {
        $user = new User;
        $user->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'email' => "$name@example.test", 'password' => 'matrix password not used',
            'clearance' => $clearance, 'status' => $status,
        ])->save();
        $user->assignRole('ci_analyst'); // roles grant capabilities, not visibility

        return $user;
    }

    private static function grant(User $user, string $grant, User $grantor): void
    {
        if ($grant === 'none') {
            return;
        }

        [$kind, $level] = explode(':', $grant);
        $teamId = null;
        if ($kind === 'team') {
            $teamId = DB::table('teams')->insertGetId(['ulid' => (string) Str::ulid(), 'name' => 'Matrix team '.$user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'added_by' => $grantor->id, 'created_at' => now()]);
        }

        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(),
            'user_id' => $teamId === null ? $user->id : null,
            'team_id' => $teamId,
            'scope_type' => 'all',
            'max_classification' => $level,
            'granted_by' => $grantor->id,
            'reason' => 'Authorization matrix',
            'expires_at' => $kind === 'expired' ? now('UTC')->subMinute() : null,
            'revoked_at' => $kind === 'revoked' ? now('UTC')->subMinute() : null,
            'revoked_by' => $kind === 'revoked' ? $grantor->id : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
