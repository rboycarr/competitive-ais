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
        $plan = $grants->apply((string) $this->option('connection'));

        if ($plan === []) {
            $this->components->warn('Nothing to apply (no tables, or not a MySQL connection).');

            return self::SUCCESS;
        }

        if ($this->output->isVerbose()) {
            $this->table(
                ['Table', 'Class', 'App', 'Retention'],
                array_map(
                    fn (string $table, array $t): array => [$table, $t['class'], implode(', ', $t['app']) ?: '-', implode(', ', $t['retention']) ?: '-'],
                    array_keys($plan),
                    $plan,
                ),
            );
        }

        $count = fn (string $class): int => count(array_filter($plan, fn (array $t): bool => $t['class'] === $class));
        $this->components->info(sprintf(
            'Grants applied to %d tables: %d mutable, %d append-only, %d no app access, %d with retention access.',
            count($plan),
            $count('mutable'),
            $count('append_only'),
            $count('app_excluded'),
            count(array_filter($plan, fn (array $t): bool => $t['retention'] !== [])),
        ));

        return self::SUCCESS;
    }
}
