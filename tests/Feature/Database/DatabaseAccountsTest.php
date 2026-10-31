<?php

namespace Tests\Feature\Database;

use App\Database\TableGrants;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        config()->set('database.grants.retention_tables.'.self::APPEND_ONLY_PROBE, ['SELECT', 'DELETE']);
        $this->app->make(TableGrants::class)->apply();
    }

    protected function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        foreach ([self::APPEND_ONLY_PROBE, self::MUTABLE_PROBE] as $table) {
            foreach ([config('database.grants.app_user'), config('database.grants.retention_user')] as $user) {
                $migrator->unprepared('REVOKE IF EXISTS '.implode(', ', TableGrants::TABLE_PRIVILEGES)." ON `$table` FROM '$user'@'%' IGNORE UNKNOWN USER");
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
