<?php

namespace Modules\Platform\Support;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Laravel\Pennant\FeatureManager;
use Modules\Platform\Audit\AuditLogger;
use Throwable;

/**
 * Feature flags (docs/01, docs/10), stored by Laravel Pennant in the `features` table for everyone at once (global
 * scope). Every flag is off until an operator switches it on with `php artisan feature:switch`, which is audited as
 * feature.switched. Only a stored true counts as on (Pennant's own active() accepts any value but false), and an
 * unknown flag is a programming error, not "off", so a typo cannot quietly disable a check. The only class that reads
 * Pennant (tests/Architecture/CodeRulesTest).
 */
final class Features
{
    public const COLLECTION = 'collection';

    /** Every flag, with its meaning for the status command. */
    public const KNOWN = [
        self::COLLECTION => 'Phase 3 automated collection; while off, sources are collected manually only (P1-30)',
    ];

    public function __construct(
        private readonly FeatureManager $pennant,
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pennant's default for every known flag: off.
     */
    public static function defineDefaults(FeatureManager $pennant): void
    {
        foreach (array_keys(self::KNOWN) as $flag) {
            $pennant->store()->define($flag, fn (): bool => false);
        }
    }

    public function enabled(string $flag): bool
    {
        return $this->pennant->store()->globally()->value(self::known($flag)) === true;
    }

    /**
     * Switches a flag for everyone and audits it; false when the flag already had that state (nothing is recorded).
     */
    public function switch(string $flag, bool $on): bool
    {
        self::known($flag);

        try {
            return $this->db->transaction(function () use ($flag, $on): bool {
                // The stored state, not this process's earlier read.
                $this->pennant->flushCache();
                if ($this->enabled($flag) === $on) {
                    return false;
                }

                $flags = $this->pennant->store()->globally();
                $on ? $flags->activate($flag) : $flags->deactivate($flag);
                $this->audit->record('feature.switched', context: ['flag' => $flag, 'enabled' => $on]);

                return true;
            });
        } catch (Throwable $e) {
            // Pennant cached the new value before the rollback; forget it so this process reads the stored one again.
            $this->pennant->flushCache();

            throw $e;
        }
    }

    private static function known(string $flag): string
    {
        if (! array_key_exists($flag, self::KNOWN)) {
            throw new InvalidArgumentException("Unknown feature flag [$flag].");
        }

        return $flag;
    }
}
