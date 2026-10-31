<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Database\TableGrants;

class ApplyDatabaseGrants extends Command
{
    protected $signature = 'db:apply-grants {--connection=migrator : Connection with GRANT OPTION on the schema}';

    protected $description = 'Bring the app and retention accounts\' table privileges in line with config(database.grants)';

    public function handle(TableGrants $grants): int
    {
        $plan = $grants->apply($this->option('connection'));

        if ($plan === []) {
            $this->components->warn('Nothing to apply (no tables, or not a MySQL connection).');

            return self::SUCCESS;
        }

        if ($this->output->isVerbose()) {
            $this->table(
                ['Table', 'App', 'Retention'],
                array_map(
                    fn (string $table, array $t): array => [$table, implode(', ', $t['app']) ?: '-', implode(', ', $t['retention']) ?: '-'],
                    array_keys($plan),
                    $plan,
                ),
            );
        }

        $count = fn (callable $filter): int => count(array_filter($plan, $filter));
        $this->components->info(sprintf(
            'Grants applied to %d tables: %d read/write, %d append-only, %d no app access, %d with retention access.',
            count($plan),
            $count(fn (array $t): bool => $t['app'] === TableGrants::MUTABLE),
            $count(fn (array $t): bool => $t['app'] === TableGrants::APPEND_ONLY),
            $count(fn (array $t): bool => $t['app'] === []),
            $count(fn (array $t): bool => $t['retention'] !== []),
        ));

        return self::SUCCESS;
    }
}
