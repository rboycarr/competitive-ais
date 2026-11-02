<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Modules\Platform\Support\Features;
use Throwable;

/**
 * Switches a feature flag on or off for everyone (docs/10 runbook). Operators only: it runs as the migrator account
 * (PlatformServiceProvider::MIGRATOR_COMMANDS), so only in the migrator service, and there is no screen for it until
 * the administration module.
 * Acts as the system; audited as feature.switched. Production asks for confirmation (or --force). A failed switch
 * is logged and ends the command normally with exit code 1, so the provider switches the default connection back
 * (TASK-0056 #2); the console shows a fixed message, never the exception's, which may hold SQL or hosts (TASK-0057).
 */
class SwitchFeature extends Command
{
    use ConfirmableTrait;

    protected $signature = 'feature:switch {flag : The flag name, e.g. collection} {state : on or off} {--force : Do not ask in production}';

    protected $description = 'Switch a feature flag on or off (audited)';

    public function handle(Features $features): int
    {
        $flag = (string) $this->argument('flag');
        $state = (string) $this->argument('state');
        if (! array_key_exists($flag, Features::KNOWN) || ! in_array($state, ['on', 'off'], true)) {
            $this->components->error('Give a known flag ('.implode(', ', array_keys(Features::KNOWN)).') and on or off.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed("Switch the [$flag] feature $state for everyone")) {
            return self::FAILURE;
        }

        try {
            $changed = $features->switch($flag, $state === 'on');
        } catch (Throwable $e) {
            report($e);
            $this->components->error("The [$flag] feature was not switched; the reason is in the application log.");

            return self::FAILURE;
        }

        if (! $changed) {
            $this->components->info("The [$flag] feature is already $state.");

            return self::SUCCESS;
        }

        $this->components->info("The [$flag] feature is now $state.");

        return self::SUCCESS;
    }
}
