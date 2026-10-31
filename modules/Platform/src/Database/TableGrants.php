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
 * apply() throws after revoking. Views are not permitted at all.
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
    public function plan(string $connection = 'migrator'): array
    {
        $classOf = $this->classes();
        $retentionPolicy = (array) $this->config->get('database.grants.retention_tables', []);

        $plan = [];
        foreach ($this->objects($connection, 'BASE TABLE') as $table) {
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
     * Apply the plan. Returns it for reporting. No-op on non-MySQL connections.
     *
     * @return array<string, array{class: string, app: list<string>, retention: list<string>}>
     *
     * @throws RuntimeException when a table is unclassified or a view exists (after revoking their access)
     */
    public function apply(string $connection = 'migrator'): array
    {
        $conn = $this->db->connection($connection);
        if ($conn->getDriverName() !== 'mysql') {
            return [];
        }

        $schema = $this->identifier($conn->getDatabaseName());
        $app = $this->account((string) $this->config->get('database.grants.app_user'));
        $retention = $this->account((string) $this->config->get('database.grants.retention_user'));

        $plan = $this->plan($connection);
        $views = $this->objects($connection, 'VIEW');

        // GRANT/REVOKE cannot take bound parameters. Every interpolated part is either a validated identifier
        // (identifier(), account()) or a privilege name from the constants above, so the raw SQL is safe.
        $targets = array_map(fn (array $t): array => [$t['app'], $t['retention']], $plan)
            + array_fill_keys($views, [[], []]);
        foreach ($targets as $object => [$appWanted, $retentionWanted]) {
            $qualified = $schema.'.'.$this->identifier($object);
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

        return $plan;
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
    private function objects(string $connection, string $type): array
    {
        $conn = $this->db->connection($connection);

        return array_values($conn->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $conn->getDatabaseName())
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
