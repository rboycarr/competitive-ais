<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
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

    /**
     * Grant variants: what the user holds (kind:classification; competitor:A|B:classification, P1-20a;
     * market:M|N:classification, P1-23c; pair:A:M:classification = a competitor A grant and a market M grant).
     */
    public const GRANTS = [
        'none', 'all:internal', 'all:confidential', 'all:restricted', 'expired:restricted', 'revoked:restricted', 'team:confidential',
        'competitor:A:confidential', 'competitor:B:restricted', 'market:M:confidential', 'market:N:restricted', 'pair:A:M:confidential',
    ];

    /** Record scopes: competitors (A, B) and markets (M, N), all real registry entries. */
    public const SCOPES = [
        'unscoped' => [[], []], 'competitor' => [['A'], []], 'competitor-b' => [['B'], []], 'competitors' => [['A', 'B'], []],
        'market' => [[], ['M']], 'market-n' => [[], ['N']], 'markets' => [[], ['M', 'N']], 'competitor-and-market' => [['A'], ['M']],
    ];

    /** @var array{A?: int, B?: int} competitor letter => ID, created by users() */
    private static array $competitors = [];

    /** @var array{M?: int, N?: int} market letter => ID, created by users() */
    private static array $markets = [];

    private const RANK = ['public' => 0, 'internal' => 1, 'confidential' => 2, 'restricted' => 3];

    /**
     * One user per combination, with the grant in the database.
     *
     * @return list<array{user: User, clearance: string, status: string, grant: string}>
     */
    public static function users(): array
    {
        $grantor = self::makeUser('matrix-grantor', 'restricted', 'active');
        self::$competitors = [];
        foreach (['A', 'B'] as $letter) {
            self::$competitors[$letter] = DB::table('competitors')->insertGetId([
                'ulid' => (string) Str::ulid(), 'name' => "Matrix competitor $letter", 'slug' => 'matrix-competitor-'.strtolower($letter).'-'.Str::lower(Str::random(6)),
                'lifecycle' => 'active', 'classification' => 'public', 'created_by' => $grantor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        self::$markets = [];
        foreach (['M', 'N'] as $letter) {
            self::$markets[$letter] = DB::table('markets')->insertGetId([
                'ulid' => (string) Str::ulid(), 'code' => "MATRIX-$letter", 'name' => "Matrix market $letter", 'country' => 'PH',
                'default_currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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

    /**
     * One record spec per classification and scope shape, with real competitor and market IDs: call users() first.
     *
     * @return list<array{label: string, classification: string, competitors: list<int>, markets: list<int>}>
     */
    public static function records(): array
    {
        $records = [];
        foreach (self::CLEARANCES as $classification) {
            foreach (self::SCOPES as $scope => [$competitors, $markets]) {
                $records[] = [
                    'label' => "$classification/$scope", 'classification' => $classification,
                    'competitors' => array_map(fn (string $letter): int => self::competitorId($letter), $competitors),
                    'markets' => array_map(fn (string $letter): int => self::marketId($letter), $markets),
                ];
            }
        }

        return $records;
    }

    public static function competitorId(string $letter): int
    {
        return self::$competitors[$letter] ?? throw new LogicException('Call AuthorizationMatrix::users() first: it creates the competitors.');
    }

    public static function marketId(string $letter): int
    {
        return self::$markets[$letter] ?? throw new LogicException('Call AuthorizationMatrix::users() first: it creates the markets.');
    }

    /**
     * docs/07 §3, as written: active account, classification within clearance, restricted only with a covering
     * grant, scoped records only with a covering grant. A grant covers a record at or below its level: `all`
     * (direct or team) any record; otherwise every competitor on the record needs a competitor grant and every
     * market a market grant (all-of): competitor A alone, market M alone, or the A+M pair for a record about A and M.
     *
     * @param  array{clearance: string, status: string, grant: string}  $user
     * @param  array{classification: string, competitors: list<int>, markets: list<int>}  $record
     */
    public static function expected(array $user, array $record): bool
    {
        if ($user['status'] !== 'active' || self::RANK[$record['classification']] > self::RANK[$user['clearance']]) {
            return false;
        }

        $parts = explode(':', $user['grant']);
        $kind = $parts[0];
        $level = end($parts);
        $opens = $kind !== 'none' && self::RANK[$level] >= self::RANK[$record['classification']];
        $scoped = $record['competitors'] !== [] || $record['markets'] !== [];

        if (in_array($kind, ['all', 'team'], true)) {
            $covered = $opens;
        } elseif (in_array($kind, ['competitor', 'market', 'pair'], true)) {
            $myCompetitors = match ($kind) {
                'competitor', 'pair' => [self::competitorId($parts[1])],
                default => [],
            };
            $myMarkets = match ($kind) {
                'market' => [self::marketId($parts[1])],
                'pair' => [self::marketId($parts[2])],
                default => [],
            };
            $covered = $opens && $scoped && array_diff($record['competitors'], $myCompetitors) === [] && array_diff($record['markets'], $myMarkets) === [];
        } else {
            $covered = false; // none, expired, revoked
        }

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

        $parts = explode(':', $grant);
        $kind = $parts[0];
        $level = end($parts);
        $teamId = null;
        if ($kind === 'team') {
            $teamId = DB::table('teams')->insertGetId(['ulid' => (string) Str::ulid(), 'name' => 'Matrix team '.$user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'added_by' => $grantor->id, 'created_at' => now()]);
        }

        // scope type => target ID; the pair holds two grants.
        $targets = match ($kind) {
            'competitor' => [['competitor', self::competitorId($parts[1]), null]],
            'market' => [['market', null, self::marketId($parts[1])]],
            'pair' => [['competitor', self::competitorId($parts[1]), null], ['market', null, self::marketId($parts[2])]],
            default => [['all', null, null]],
        };
        foreach ($targets as [$scope, $competitorId, $marketId]) {
            DB::table('access_grants')->insert([
                'ulid' => (string) Str::ulid(),
                'user_id' => $teamId === null ? $user->id : null,
                'team_id' => $teamId,
                'scope_type' => $scope,
                'competitor_id' => $competitorId,
                'market_id' => $marketId,
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
}
