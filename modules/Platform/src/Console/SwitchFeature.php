<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Modules\Platform\Support\Features;

/**
 * Switches a feature flag on or off for everyone (docs/10 runbook). Operators only: it writes on the migrator
 * account, so it runs only in the migrator service, and there is no screen for it until the administration module.
 * Acts as the system; audited as feature.switched. Production asks for confirmation (or --force).
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

        if (! is_array(config('database.connections.'.Features::WRITER))) {
            $this->components->error("Switching a flag needs the migrator account: docker compose run --rm migrator php artisan feature:switch $flag $state");

            return self::FAILURE;
        }

        if (! $this->confirmToProceed("Switch the [$flag] feature $state for everyone")) {
            return self::FAILURE;
        }

        if (! $features->switch($flag, $state === 'on')) {
            $this->components->info("The [$flag] feature is already $state.");

            return self::SUCCESS;
        }

        $this->components->info("The [$flag] feature is now $state.");

        return self::SUCCESS;
    }
}
