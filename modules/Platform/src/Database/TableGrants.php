<?php

namespace Modules\Platform\Database;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Keeps the app and retention accounts' table privileges equal to the policy in config('database.grants').
 *
 * Runs as the migrator (which holds these privileges WITH GRANT OPTION, docs/02 §6). Each table is brought to
 * its target by GRANTing the wanted privileges and REVOKE IF EXISTS on the rest, so there is no window in which
 * a privilege the app needs is missing. Schema- and global-level privileges are owned by the MySQL init script.
 */
class TableGrants
{
    /** Table-level privileges the migrator can grant and revoke. */
    public const TABLE_PRIVILEGES = [
        'ALTER', 'CREATE', 'CREATE VIEW', 'DELETE', 'DROP', 'INDEX', 'INSERT', 'REFERENCES', 'SELECT', 'SHOW VIEW', 'UPDATE',
    ];

    public const MUTABLE = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public const APPEND_ONLY = ['SELECT', 'INSERT'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    /**
     * Target privileges per existing base table.
     *
     * @return array<string, array{app: list<string>, retention: list<string>}>
     */
    public function plan(string $connection = 'migrator'): array
    {
        $policy = $this->config->get('database.grants');
        $conn = $this->db->connection($connection);

        $tables = array_map(
            fn (object $row): string => $row->name,
            $conn->select(
                'select TABLE_NAME as name from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_TYPE = ? order by TABLE_NAME',
                [$conn->getDatabaseName(), 'BASE TABLE'],
            ),
        );

        $plan = [];
        foreach ($tables as $table) {
            $app = match (true) {
                in_array($table, $policy['app_excluded'], true) => [],
                in_array($table, $policy['append_only'], true) => self::APPEND_ONLY,
                default => self::MUTABLE,
            };

            $retention = array_map('strtoupper', $policy['retention_tables'][$table] ?? []);
            if (array_diff($retention, ['SELECT', 'DELETE']) !== []) {
                throw new InvalidArgumentException("Retention privileges for [$table] may only be SELECT and DELETE.");
            }

            $plan[$table] = ['app' => $app, 'retention' => array_values($retention)];
        }

        return $plan;
    }

    /**
     * Apply the plan. Returns it for reporting. No-op on non-MySQL connections.
     *
     * @return array<string, array{app: list<string>, retention: list<string>}>
     */
    public function apply(string $connection = 'migrator'): array
    {
        $conn = $this->db->connection($connection);
        if ($conn->getDriverName() !== 'mysql') {
            return [];
        }

        $policy = $this->config->get('database.grants');
        $schema = $this->identifier($conn->getDatabaseName());
        $app = $this->account($policy['app_user'], $policy['host']);
        $retention = $this->account($policy['retention_user'], $policy['host']);

        $plan = $this->plan($connection);
        foreach ($plan as $table => $target) {
            $object = $schema.'.'.$this->identifier($table);
            foreach ([[$app, $target['app']], [$retention, $target['retention']]] as [$account, $wanted]) {
                if ($wanted !== []) {
                    $conn->unprepared('GRANT '.implode(', ', $wanted)." ON $object TO $account");
                }
                $unwanted = array_diff(self::TABLE_PRIVILEGES, $wanted);
                $conn->unprepared('REVOKE IF EXISTS '.implode(', ', $unwanted)." ON $object FROM $account IGNORE UNKNOWN USER");
            }
        }

        return $plan;
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException("Unsafe identifier [$name].");
        }

        return '`'.$name.'`';
    }

    private function account(string $user, string $host): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,31}$/', $user) || ! preg_match('/^[A-Za-z0-9.%_-]{1,255}$/', $host)) {
            throw new InvalidArgumentException('Unsafe account name in database.grants.');
        }

        return "'$user'@'$host'";
    }
}
