<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\Platform\Audit\AuditAnchor;
use RuntimeException;

/**
 * Re-anchors a signed range after a late commit, once the runbook's investigation found the extra rows genuine
 * (P1-50, docs/10 §6): a supplementary checkpoint, signed, exported and audited as `audit.reanchored`.
 */
class ReanchorAuditRange extends Command
{
    protected $signature = 'audit:reanchor
        {from : The first audit event ID of the range (from audit:verify)}
        {--late= : The late-committed audit event IDs the investigation found, comma-separated (required)}
        {--reason= : What the investigation found (required, at most 500 characters, recorded in the audit trail)}';

    protected $description = 'Re-anchor an audit range after an investigated late commit';

    public function handle(AuditAnchor $anchor): int
    {
        $from = filter_var($this->argument('from'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($from === false) {
            $this->components->error('The first audit ID must be a positive integer.');

            return self::INVALID;
        }

        $late = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('late'))), fn (string $id): bool => $id !== '') as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) {
                $this->components->error('The late IDs must be positive integers, separated by commas.');

                return self::INVALID;
            }
            $late[] = $value;
        }

        try {
            $result = $anchor->reanchor($from, $late, (string) $this->option('reason'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Re-anchored IDs {$result['from']}-{$result['to']} as revision {$result['revision']}: {$result['count']} events (was {$result['previous_count']}).");

        return self::SUCCESS;
    }
}
