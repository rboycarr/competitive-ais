<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Support\Features;

/** Lists every feature flag and whether it is on (docs/10 runbook). */
class ShowFeatures extends Command
{
    protected $signature = 'feature:status';

    protected $description = 'Show whether each feature flag is on';

    public function handle(Features $features): int
    {
        $rows = [];
        foreach (Features::KNOWN as $flag => $meaning) {
            $rows[] = [$flag, $features->enabled($flag) ? 'on' : 'off', $meaning];
        }
        $this->table(['Flag', 'State', 'Meaning'], $rows);

        return self::SUCCESS;
    }
}
