<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Support\Features;

/**
 * Lists every feature flag, whether it is on, and whether its stored state matches its last audited switch (docs/10
 * runbook and monitoring); exits 1 when a flag was changed without feature:switch (TASK-0054 #2).
 */
class ShowFeatures extends Command
{
    protected $signature = 'feature:status';

    protected $description = 'Show whether each feature flag is on';

    public function handle(Features $features): int
    {
        $rows = [];
        $unaudited = [];
        foreach (Features::KNOWN as $flag => $meaning) {
            $audited = $features->matchesAuditTrail($flag);
            if (! $audited) {
                $unaudited[] = $flag;
            }
            $rows[] = [$flag, $features->enabled($flag) ? 'on' : 'off', $audited ? 'yes' : 'NO', $meaning];
        }
        $this->table(['Flag', 'State', 'Matches audit trail', 'Meaning'], $rows);

        if ($unaudited !== []) {
            $this->components->error('Changed without feature:switch, so read as off: '.implode(', ', $unaudited)
                .'. Investigate, then set the intended state with feature:switch.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
