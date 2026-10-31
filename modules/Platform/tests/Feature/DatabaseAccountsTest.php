<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Platform\Database\TableGrants;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-09 / docs/02 §6 / docs/09 #7: every connection runs with least privilege.
 *
 * The real append-only tables arrive with later stories, so the mechanism is proven on probe tables created
 * by the migrator; a second set of checks compares the app account's actual grants on every real table with
 * the policy.
 */
class DatabaseAccountsTest extends TestCase
{
    use DatabaseMigrations;

    private const APPEND_ONLY_PROBE = 'zz_probe_append_only';

    private const MUTABLE_PROBE = 'zz_probe_mutable';

    private const UNCLASSIFIED_PROBE = 'zz_probe_unclassified';

    /** MySQL ER_TABLEACCESS_DENIED_ERROR */
    private const ACCESS_DENIED = 1142;

    protected function setUp(): void
    {
        parent::setUp();

        $migrator = DB::connection('migrator');
        foreach ([self::APPEND_ONLY_PROBE, self::MUTABLE_PROBE] as $table) {
            $migrator->statement("create table `$table` (id bigint unsigned auto_increment primary key, note varchar(50) not null)");
        }

        config()->push('database.grants.append_only', self::APPEND_ONLY_PROBE);
        config()->push('database.grants.mutable', self::MUTABLE_PROBE);
        config()->set('database.grants.retention_tables.'.self::APPEND_ONLY_PROBE, ['SELECT', 'DELETE']);
        $this->app->make(TableGrants::class)->apply();
    }

    protected function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        foreach ([self::APPEND_ONLY_PROBE, self::MUTABLE_PROBE, self::UNCLASSIFIED_PROBE] as $table) {
            foreach ([config('database.grants.app_user'), config('database.grants.retention_user')] as $user) {
                $migrator->unprepared('REVOKE IF EXISTS '.implode(', ', TableGrants::TABLE_PRIVILEGES)." ON `$table` FROM '$user'@'%' IGNORE UNKNOWN USER"); // @phpstan-ignore argument.type (constant table names, configured account names)
            }
            $migrator->statement("drop table if exists `$table`");
        }

        parent::tearDown();
    }

    public function test_default_connection_is_the_app_account(): void
    {
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertStringStartsWith(config('database.grants.app_user').'@', DB::scalar('select current_user()'));
        $this->assertStringStartsWith(config('database.connections.migrator.username').'@', DB::connection('migrator')->scalar('select current_user()'));
        $this->assertStringStartsWith(config('database.grants.retention_user').'@', DB::connection('retention')->scalar('select current_user()'));
    }

    public function test_migration_commands_run_as_migrator_and_restore_the_app_connection(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--no-interaction' => true]));
        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_app_can_read_and_write_mutable_tables(): void
    {
        $id = DB::table(self::MUTABLE_PROBE)->insertGetId(['note' => 'a']);
        $this->assertSame(1, DB::table(self::MUTABLE_PROBE)->where('id', $id)->update(['note' => 'b']));
        $this->assertSame('b', DB::table(self::MUTABLE_PROBE)->where('id', $id)->value('note'));
        $this->assertSame(1, DB::table(self::MUTABLE_PROBE)->where('id', $id)->delete());
    }

    public function test_app_can_insert_and_read_append_only_tables(): void
    {
        $id = DB::table(self::APPEND_ONLY_PROBE)->insertGetId(['note' => 'recorded']);
        $this->assertSame('recorded', DB::table(self::APPEND_ONLY_PROBE)->where('id', $id)->value('note'));
    }

    public function test_app_cannot_update_or_delete_append_only_tables(): void
    {
        $id = DB::table(self::APPEND_ONLY_PROBE)->insertGetId(['note' => 'recorded']);

        $this->assertDenied(fn () => DB::table(self::APPEND_ONLY_PROBE)->where('id', $id)->update(['note' => 'changed']));
        $this->assertDenied(fn () => DB::table(self::APPEND_ONLY_PROBE)->where('id', $id)->delete());
        $this->assertDenied(fn () => DB::statement('truncate table `'.self::APPEND_ONLY_PROBE.'`'));
        $this->assertSame('recorded', DB::table(self::APPEND_ONLY_PROBE)->where('id', $id)->value('note'));
    }

    public function test_app_cannot_change_the_schema(): void
    {
        $this->assertDenied(fn () => DB::statement('create table zz_probe_new (id int primary key)'));
        $this->assertDenied(fn () => DB::statement('alter table `'.self::MUTABLE_PROBE.'` add column extra int'));
        $this->assertDenied(fn () => DB::statement('drop table `'.self::MUTABLE_PROBE.'`'));
    }

    public function test_app_has_no_access_to_the_migrations_table(): void
    {
        $this->assertDenied(fn () => DB::table('migrations')->count());
    }

    public function test_app_has_no_schema_or_global_privileges(): void
    {
        $grants = array_map(fn (object $row): string => array_values((array) $row)[0], DB::select('show grants for current_user()'));

        foreach ($grants as $grant) {
            if (preg_match('/ ON (\*\.\*|`[^`]+`\.\*) TO /', $grant)) {
                $this->assertMatchesRegularExpression('/^GRANT USAGE ON \*\.\* TO /', $grant, "Unexpected broad grant: $grant");
            }
        }
    }

    public function test_app_grants_on_every_table_match_the_policy(): void
    {
        $actual = [];
        foreach (DB::select('select TABLE_NAME as t, PRIVILEGE_TYPE as p from information_schema.TABLE_PRIVILEGES where TABLE_SCHEMA = database()') as $row) {
            $actual[$row->t][] = $row->p;
        }

        $plan = $this->app->make(TableGrants::class)->plan();
        $this->assertArrayHasKey('users', $plan, 'Expected the skeleton tables to be migrated.');

        foreach ($plan as $table => $target) {
            $have = $actual[$table] ?? [];
            sort($have);
            $want = $target['app'];
            sort($want);
            $this->assertSame($want, $have, "App grants on [$table]");
        }
    }

    public function test_retention_account_can_only_select_and_delete_its_tables(): void
    {
        $id = DB::table(self::APPEND_ONLY_PROBE)->insertGetId(['note' => 'expired']);
        $retention = DB::connection('retention');

        $this->assertSame(1, $retention->table(self::APPEND_ONLY_PROBE)->where('id', $id)->delete());
        $this->assertDenied(fn () => $retention->table(self::APPEND_ONLY_PROBE)->insert(['note' => 'x']));
        $this->assertDenied(fn () => $retention->table(self::APPEND_ONLY_PROBE)->update(['note' => 'x']));
        $this->assertDenied(fn () => $retention->table(self::MUTABLE_PROBE)->count());
        $this->assertDenied(fn () => $retention->table('users')->count());
    }

    public function test_applying_grants_is_idempotent(): void
    {
        $tables = $this->app->make(TableGrants::class);

        $this->assertSame($tables->apply(), $tables->apply());
        $this->assertSame(0, Artisan::call('db:apply-grants'));
        $this->test_app_cannot_update_or_delete_append_only_tables();
    }

    public function test_grants_are_checked_against_the_policy_table_by_table(): void
    {
        $tables = $this->app->make(TableGrants::class);
        $this->assertSame([], $tables->verify());

        // Out-of-band grants of the kind the MySQL init script used to accept (TASK-0005 #1).
        $app = config('database.grants.app_user');
        $migrator = DB::connection('migrator');
        $migrator->unprepared("GRANT DELETE ON `migrations` TO '$app'@'%'"); // @phpstan-ignore argument.type (configured account name)
        $migrator->unprepared('GRANT UPDATE ON `'.self::APPEND_ONLY_PROBE."` TO '$app'@'%'"); // @phpstan-ignore argument.type (constant table, configured account)

        $db = $migrator->getDatabaseName();
        $problems = implode("\n", $tables->verify());
        $this->assertStringContainsString("app on [$db.migrations] has DELETE; the policy is nothing", $problems);
        $this->assertStringContainsString("app on [$db.".self::APPEND_ONLY_PROBE.'] has INSERT, SELECT, UPDATE; the policy is INSERT, SELECT', $problems);

        $tables->apply(); // restores the policy, and would throw if it could not
        $this->assertSame([], $tables->verify());
    }

    public function test_grants_are_only_applied_to_a_listed_database(): void
    {
        $databases = config('database.grants.databases');
        config()->set('database.grants.databases', ['zz_some_other_database']);

        try {
            $this->app->make(TableGrants::class)->apply();
            $this->fail('Expected apply() to refuse a database outside database.grants.databases.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is not in database.grants.databases', $e->getMessage());
        } finally {
            config()->set('database.grants.databases', $databases); // teardown migrations re-apply grants
        }
    }

    public function test_grants_on_a_dropped_table_are_removed_before_it_can_be_recreated(): void
    {
        $migrator = DB::connection('migrator');
        $db = $migrator->getDatabaseName();
        $migrator->statement('drop table `'.self::APPEND_ONLY_PROBE.'`'); // MySQL keeps the grants of both accounts
        $problems = implode("\n", $this->app->make(TableGrants::class)->verify());
        $this->assertStringContainsString("app on [$db.".self::APPEND_ONLY_PROBE.'] has INSERT, SELECT; the policy is nothing', $problems);
        $this->assertStringContainsString("retention on [$db.".self::APPEND_ONLY_PROBE.'] has DELETE, SELECT; the policy is nothing', $problems);

        $this->app->make(TableGrants::class)->apply();

        // Re-created: neither account inherited anything; access comes only from the next apply().
        $migrator->statement('create table `'.self::APPEND_ONLY_PROBE.'` (id bigint unsigned primary key)');
        $this->assertDenied(fn () => DB::table(self::APPEND_ONLY_PROBE)->count());
        $problems = implode("\n", $this->app->make(TableGrants::class)->verify());
        $this->assertStringContainsString("app on [$db.".self::APPEND_ONLY_PROBE.'] has nothing; the policy is INSERT, SELECT', $problems);
        $this->assertStringContainsString("retention on [$db.".self::APPEND_ONLY_PROBE.'] has nothing; the policy is DELETE, SELECT', $problems);
    }

    public function test_unclassified_table_gets_no_access_and_fails_the_grant_run(): void
    {
        DB::connection('migrator')->statement('create table `'.self::UNCLASSIFIED_PROBE.'` (id bigint unsigned primary key)');

        try {
            $this->app->make(TableGrants::class)->apply();
            $this->fail('Expected apply() to reject the unclassified table.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(self::UNCLASSIFIED_PROBE, $e->getMessage());
        }

        $this->assertDenied(fn () => DB::table(self::UNCLASSIFIED_PROBE)->count());
        $this->assertDenied(fn () => DB::table(self::UNCLASSIFIED_PROBE)->insert(['id' => 1]));
    }

    public function test_a_table_cannot_be_in_two_classes(): void
    {
        $mutable = config('database.grants.mutable');
        config()->push('database.grants.mutable', self::APPEND_ONLY_PROBE);

        try {
            $this->app->make(TableGrants::class)->plan();
            $this->fail('Expected the duplicate classification to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('listed in both', $e->getMessage());
        } finally {
            config()->set('database.grants.mutable', $mutable); // teardown migrations re-apply grants
        }
    }

    public function test_migrator_cannot_create_views(): void
    {
        $this->assertDenied(fn () => DB::connection('migrator')->statement('create view zz_probe_view as select 1 as x'));
    }

    public function test_every_existing_append_only_table_rejects_update_delete_and_truncate(): void
    {
        $migrator = DB::connection('migrator');
        $checked = 0;

        foreach ((array) config('database.grants.append_only') as $table) {
            $column = $migrator->table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $migrator->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->orderBy('ORDINAL_POSITION')
                ->value('COLUMN_NAME');
            if ($column === null) {
                continue; // not migrated yet; covered automatically once its migration exists
            }

            $privileges = DB::table('information_schema.TABLE_PRIVILEGES')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->orderBy('PRIVILEGE_TYPE')
                ->pluck('PRIVILEGE_TYPE')
                ->all();
            $this->assertSame(['INSERT', 'SELECT'], $privileges, "App grants on append-only [$table]");

            // The privilege check happens before any row is touched, so WHERE 1 = 0 is enough.
            $this->assertDenied(fn () => DB::table($table)->whereRaw('1 = 0')->update([$column => DB::raw("`$column`")])); // @phpstan-ignore argument.type (column name read from information_schema)
            $this->assertDenied(fn () => DB::table($table)->whereRaw('1 = 0')->delete());
            $this->assertDenied(fn () => DB::statement("truncate table `$table`"));
            $checked++;
        }

        $this->assertGreaterThanOrEqual(1, $checked, 'Expected at least the probe table to be checked.');
    }

    public function test_migrator_connection_is_refused_where_its_credentials_are_absent(): void
    {
        $migrator = config('database.connections.migrator');
        DB::purge('migrator');
        config()->set('database.connections.migrator', null);

        try {
            try {
                DB::connection('migrator');
                $this->fail('Expected the migrator connection to be unavailable.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not configured', $e->getMessage());
            }

            try {
                Artisan::call('migrate:status');
                $this->fail('Expected migrate:status to be refused.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('only available in the migrator service', $e->getMessage());
            }
            $this->assertSame('mysql', DB::getDefaultConnection());
        } finally {
            config()->set('database.connections.migrator', $migrator);
        }
    }

    private function assertDenied(callable $query): void
    {
        try {
            $query();
        } catch (QueryException $e) {
            $this->assertSame(self::ACCESS_DENIED, $e->errorInfo[1] ?? null, 'Expected access denied, got: '.$e->getMessage());

            return;
        }

        $this->fail('Expected the query to be denied, but it succeeded.');
    }
}
