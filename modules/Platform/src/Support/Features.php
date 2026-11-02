<?php

namespace Modules\Platform\Support;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Laravel\Pennant\FeatureManager;
use Laravel\Pennant\GlobalScope;
use LogicException;
use Modules\Platform\Audit\AuditLogger;
use RuntimeException;

/**
 * Feature flags (docs/01, docs/10), one global row per flag in the `features` table. Read through Laravel Pennant;
 * the app account can only read the table (grant class read_only, TASK-0053 #1), so nothing in the running app can
 * switch a flag. Every flag is off until an operator runs `php artisan feature:switch` in the migrator service, which
 * writes on the migrator account and audits feature.switched in the same transaction. Only a stored true counts as
 * on (Pennant's own active() accepts any value but false), and an unknown flag is a programming error, not "off", so
 * a typo cannot quietly disable a check. The only class that reads Pennant (tests/Architecture/CodeRulesTest).
 */
final class Features
{
    public const COLLECTION = 'collection';

    /** Every flag, with its meaning for the status command. Each needs a migration that stores its row (off). */
    public const KNOWN = [
        self::COLLECTION => 'Phase 3 automated collection; while off, sources are collected manually only (P1-30)',
    ];

    /** The only connection that may write flags; configured only in the migrator service (docs/02 §6). */
    public const WRITER = 'migrator';

    public function __construct(
        private readonly FeatureManager $pennant,
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pennant's default for every known flag: off. Never stored by the app (the migration stores each row).
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
     * The flag's row is locked first, so concurrent switches run one after the other and each audited change is a
     * real transition (TASK-0053 #2).
     */
    public function switch(string $flag, bool $on): bool
    {
        self::known($flag);
        if (! is_array(config('database.connections.'.self::WRITER))) {
            throw new RuntimeException('Switching a feature flag needs the migrator account, which is only available in the migrator service: '
                ."docker compose run --rm migrator php artisan feature:switch $flag ".($on ? 'on' : 'off'));
        }

        $writer = $this->db->connection(self::WRITER);
        $scope = $this->pennant->serializeScope(new GlobalScope);
        try {
            return $writer->transaction(function () use ($writer, $flag, $scope, $on): bool {
                $row = $writer->table('features')->where('name', $flag)->where('scope', $scope)->lockForUpdate()->first(['id', 'value']);
                if ($row === null) {
                    throw new LogicException("Feature flag [$flag] has no stored row; its migration must create it.");
                }
                if ((json_decode((string) $row->value, flags: JSON_THROW_ON_ERROR) === true) === $on) {
                    return false;
                }

                $writer->table('features')->where('id', $row->id)->update(['value' => json_encode($on), 'updated_at' => now()]);
                $this->audit->on(self::WRITER)->record('feature.switched', context: ['flag' => $flag, 'enabled' => $on]);

                return true;
            });
        } finally {
            // This process reads the stored state from now on, whatever happened.
            $this->pennant->flushCache();
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
