<?php

namespace Modules\Platform\Database;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Keeps the app and retention accounts' table privileges equal to the policy in config('database.grants').
 *
 * Runs as the migrator (which holds these privileges WITH GRANT OPTION, docs/02 §6). Each table is brought to
 * its target by GRANTing the wanted privileges and REVOKE IF EXISTS on the rest, so there is no window in which
 * a privilege the app needs is missing. Schema- and global-level privileges are owned by the MySQL init script.
 *
 * Fails closed (TASK-0003 #2, #3): every table must be classified; an unclassified table gets no app access and
 * apply() throws after revoking. Views are not permitted at all. After applying, each account's own SHOW GRANTS
 * must equal the plan table by table (TASK-0005 #1), so privileges this class cannot revoke are caught too.
 */
class TableGrants
{
    /** Table-level privileges the migrator can grant and revoke. */
    public const TABLE_PRIVILEGES = [
        'ALTER', 'CREATE', 'DELETE', 'DROP', 'INDEX', 'INSERT', 'REFERENCES', 'SELECT', 'UPDATE',
    ];

    public const MUTABLE = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public const APPEND_ONLY = ['SELECT', 'INSERT'];

    private const CLASSES = ['mutable', 'append_only', 'app_excluded'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {}

    /**
     * Target privileges per existing table, with its class ('unclassified' when the policy does not list it).
     *
     * @return array<string, array{class: string, app: list<string>, retention: list<string>}>
     */
    public function plan(string $connection = 'migrator', ?string $schema = null): array
    {
        $classOf = $this->classes();
        $retentionPolicy = (array) $this->config->get('database.grants.retention_tables', []);

        $plan = [];
        foreach ($this->objects($connection, 'BASE TABLE', $schema) as $table) {
            $class = $classOf[$table] ?? 'unclassified';
            $app = match ($class) {
                'mutable' => self::MUTABLE,
                'append_only' => self::APPEND_ONLY,
                default => [],
            };

            $retention = array_values(array_map('strtoupper', (array) ($retentionPolicy[$table] ?? [])));
            if (array_diff($retention, ['SELECT', 'DELETE']) !== []) {
                throw new InvalidArgumentException("Retention privileges for [$table] may only be SELECT and DELETE.");
            }

            $plan[$table] = ['class' => $class, 'app' => $app, 'retention' => $retention];
        }

        return $plan;
    }

    /**
     * Apply the plan to the connection's own database, then verify. Returns the plan for reporting. Other
     * databases are never changed (TASK-0008 #1): each managed database is applied by migrations run against it.
     * No-op on non-MySQL connections.
     *
     * @return array<string, array{class: string, app: list<string>, retention: list<string>}>
     *
     * @throws RuntimeException when a table is unclassified, a view exists (after revoking their access), or the
     *                          resulting grants differ from the plan
     */
    public function apply(string $connection = 'migrator'): array
    {
        $conn = $this->db->connection($connection);
        if ($conn->getDriverName() !== 'mysql') {
            return [];
        }

        $db = $conn->getDatabaseName();
        $this->managed($connection);
        $schema = $this->identifier($db);
        $app = $this->account((string) $this->config->get('database.grants.app_user'));
        $retention = $this->account((string) $this->config->get('database.grants.retention_user'));

        $plan = $this->plan($connection);
        $views = $this->objects($connection, 'VIEW');

        // GRANT/REVOKE cannot take bound parameters. Every interpolated part is either a validated identifier
        // (identifier(), account()) or a privilege name from the constants above, so the raw SQL is safe.
        // Views, and tables that were dropped but whose grants MySQL keeps (a re-created table would inherit them),
        // are revoked completely.
        $targets = array_map(fn (array $t): array => [$t['app'], $t['retention']], $plan)
            + array_fill_keys($views, [[], []]);
        foreach (['app', 'retention'] as $role) {
            $ignored = [];
            $granted = $this->granted($db, $role, $ignored) ?? [];
            $targets += array_fill_keys(array_map('strval', array_keys($granted[$db] ?? [])), [[], []]);
        }
        foreach ($targets as $object => [$appWanted, $retentionWanted]) {
            $qualified = $schema.'.'.$this->identifier((string) $object);
            foreach ([[$app, $appWanted], [$retention, $retentionWanted]] as [$account, $wanted]) {
                if ($wanted !== []) {
                    $conn->unprepared('GRANT '.implode(', ', $wanted)." ON $qualified TO $account"); // @phpstan-ignore argument.type
                }
                $unwanted = array_diff(self::TABLE_PRIVILEGES, $wanted);
                $conn->unprepared('REVOKE IF EXISTS '.implode(', ', $unwanted)." ON $qualified FROM $account IGNORE UNKNOWN USER"); // @phpstan-ignore argument.type
            }
        }

        $unclassified = array_keys(array_filter($plan, fn (array $t): bool => $t['class'] === 'unclassified'));
        if ($unclassified !== [] || $views !== []) {
            throw new RuntimeException(trim(
                ($unclassified !== [] ? 'Unclassified tables (no app access granted; add each to database.grants mutable, append_only or app_excluded): '.implode(', ', $unclassified).'. ' : '')
                .($views !== [] ? 'Views are not permitted: '.implode(', ', $views).'.' : '')
            ));
        }

        $problems = $this->verify($connection);
        if ($problems !== []) {
            throw new RuntimeException('Table grants differ from the policy after applying: '.implode('; ', $problems));
        }

        return $plan;
    }

    /**
     * Compare the app and retention accounts' actual grants, read through their own connections with SHOW GRANTS,
     * with the plan for the connection's database (TASK-0005 #1). Any extra, missing, column-level or WITH GRANT
     * OPTION privilege there is a problem. Grants on the other managed databases are checked when their own grants
     * are applied; a grant on any database outside database.grants.databases is a problem (TASK-0006 #1).
     *
     * @return list<string> problems; empty when the grants match the plan exactly
     */
    public function verify(string $connection = 'migrator'): array
    {
        $db = $this->db->connection($connection)->getDatabaseName();
        $managed = $this->managed($connection);
        $plan = $this->plan($connection);
        $problems = [];

        foreach (['app', 'retention'] as $role) {
            $granted = $this->granted($db, $role, $problems);
            if ($granted === null) {
                continue;
            }

            foreach (array_diff(array_map('strval', array_keys($granted)), $managed) as $other) {
                $problems[] = "$role has grants on [$other], which is not a managed database";
            }

            $want = [];
            foreach ($plan as $table => $target) {
                if ($target[$role] !== []) {
                    $want[$table] = $this->sorted($target[$role]);
                }
            }
            $have = $granted[$db] ?? [];

            foreach (array_keys($want + $have) as $table) {
                if (($want[$table] ?? []) !== ($have[$table] ?? [])) {
                    $problems[] = sprintf('%s on [%s.%s] has %s; the policy is %s', $role, $db, $table,
                        implode(', ', $have[$table] ?? []) ?: 'nothing', implode(', ', $want[$table] ?? []) ?: 'nothing');
                }
            }
        }

        return $problems;
    }

    /**
     * Database => table => sorted privileges the account holds, from its own SHOW GRANTS; null when its connection
     * points at a database other than $schema. Every line must name the configured account (TASK-0006 #3); lines
     * that are not plain table-level grants are added to $problems.
     *
     * @param  list<string>  $problems
     * @return array<string, array<string, list<string>>>|null
     */
    private function granted(string $schema, string $role, array &$problems): ?array
    {
        $name = (string) $this->config->get("database.grants.{$role}_connection");
        $config = (array) $this->config->get("database.connections.$name");
        if (($config['database'] ?? null) !== $schema) {
            $problems[] = "$role connection [$name] does not use database [$schema]";

            return null;
        }

        $user = (string) $this->config->get("database.grants.{$role}_user");
        $this->account($user); // validates the name
        $principal = ' TO `'.$user.'`@`'.$this->config->get('database.grants.host').'`';
        $tableGrant = '/^GRANT ([A-Z, ]+) ON `((?:[^`]|``)+)`\.`((?:[^`]|``)+)`'.preg_quote($principal, '/').'$/';

        // Without a default database: an account with no table in this schema cannot select it (error 1044).
        $conn = $this->db->connectUsing("grants-check-$role", ['database' => ''] + $config, true);
        try {
            $rows = $conn->select('SHOW GRANTS');
        } finally {
            $this->db->purge("grants-check-$role");
        }

        $granted = [];
        foreach ($rows as $row) {
            $line = (string) array_values((array) $row)[0];
            if ($line === 'GRANT USAGE ON *.*'.$principal) {
                continue;
            }
            if (! preg_match($tableGrant, $line, $m)) {
                $problems[] = "$role has an unexpected grant: $line";

                continue;
            }
            $granted[str_replace('``', '`', $m[2])][str_replace('``', '`', $m[3])] = $this->sorted(explode(', ', $m[1]));
        }

        return $granted;
    }

    /**
     * The managed databases: the explicit list in database.grants.databases (TASK-0007 #1, #2), which share this
     * policy. Each must exist and be visible to the migrator, and the connection's own database must be one of
     * them. Only the connection's own database is ever changed.
     *
     * @return list<string>
     */
    private function managed(string $connection): array
    {
        $conn = $this->db->connection($connection);
        $configured = array_values(array_unique(array_map('strval', (array) $this->config->get('database.grants.databases', []))));
        foreach ($configured as $db) {
            $this->identifier($db);
        }
        if (! in_array($conn->getDatabaseName(), $configured, true)) {
            throw new RuntimeException("Database [{$conn->getDatabaseName()}] is not in database.grants.databases.");
        }

        $visible = $conn->table('information_schema.SCHEMATA')->whereIn('SCHEMA_NAME', $configured)
            ->pluck('SCHEMA_NAME')->map(fn (mixed $name): string => (string) $name)->all();
        $missing = array_diff($configured, $visible);
        if ($missing !== []) {
            throw new RuntimeException('Managed databases not found or not accessible to the migrator: '.implode(', ', $missing).'.');
        }
        sort($configured);

        return $configured;
    }

    /**
     * @param  list<string>  $privileges
     * @return list<string>
     */
    private function sorted(array $privileges): array
    {
        sort($privileges);

        return $privileges;
    }

    /**
     * Table name => class, validated: every class is a list of plain names, and no table is in two classes.
     *
     * @return array<string, string>
     */
    private function classes(): array
    {
        $classOf = [];
        foreach (self::CLASSES as $class) {
            foreach ((array) $this->config->get("database.grants.$class", []) as $table) {
                $table = (string) $table;
                $this->identifier($table);
                if (isset($classOf[$table])) {
                    throw new InvalidArgumentException("Table [$table] is listed in both database.grants.{$classOf[$table]} and database.grants.$class.");
                }
                $classOf[$table] = $class;
            }
        }

        return $classOf;
    }

    /**
     * @return list<string>
     */
    private function objects(string $connection, string $type, ?string $schema = null): array
    {
        $conn = $this->db->connection($connection);

        return array_values($conn->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $schema ?? $conn->getDatabaseName())
            ->where('TABLE_TYPE', $type)
            ->orderBy('TABLE_NAME')
            ->pluck('TABLE_NAME')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException("Unsafe identifier [$name].");
        }

        return '`'.$name.'`';
    }

    private function account(string $user): string
    {
        $host = (string) $this->config->get('database.grants.host');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,31}$/', $user) || ! preg_match('/^[A-Za-z0-9.%_-]{1,255}$/', $host)) {
            throw new InvalidArgumentException('Unsafe account name in database.grants.');
        }

        return "'$user'@'$host'";
    }
}
