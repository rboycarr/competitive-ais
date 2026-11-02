<?php

namespace Modules\Platform\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Laravel\Pennant\FeatureManager;
use Laravel\Pennant\GlobalScope;
use LogicException;
use Modules\Platform\Audit\AuditLogger;

/**
 * Feature flags (docs/01, docs/10), one global row per flag in the `features` table. Read through Laravel Pennant;
 * the app account can only read the table (grant class read_only, TASK-0053 #1), so nothing in the running app can
 * switch a flag. Every flag is off until an operator runs `php artisan feature:switch` in the migrator service: like
 * the schema commands, it runs on the migrator account (PlatformServiceProvider::MIGRATOR_COMMANDS), so switch()
 * writes and audits feature.switched in one transaction there, and is refused anywhere else. Only a stored true counts as
 * on (Pennant's own active() accepts any value but false), and only while the flag's last feature.switched audit row
 * also says on: a row changed some other way, e.g. directly on the migrator account, reads as off and is reported by
 * feature:status (TASK-0054 #2). An unknown flag is a programming error, not "off", so a typo cannot quietly disable
 * a check. The only class that reads Pennant (tests/Architecture/CodeRulesTest).
 */
final class Features
{
    public const COLLECTION = 'collection';

    /** Every flag, with its meaning for the status command. Each needs a migration that stores its row (off). */
    public const KNOWN = [
        self::COLLECTION => 'Phase 3 automated collection; while off, sources are collected manually only (P1-30)',
    ];

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
        return $this->stored($flag) && $this->lastAudited($flag);
    }

    /**
     * Whether the stored state is the one the flag's last feature.switched audit row set (off when there is none);
     * false means the row was changed without feature:switch.
     */
    public function matchesAuditTrail(string $flag): bool
    {
        $this->pennant->flushCache();

        return $this->stored($flag) === $this->lastAudited($flag);
    }

    private function stored(string $flag): bool
    {
        return $this->pennant->store()->globally()->value(self::known($flag)) === true;
    }

    /** The state the flag's last feature.switched audit row set; off when it was never switched. */
    private function lastAudited(string $flag, ?ConnectionInterface $connection = null): bool
    {
        // Newest first along the (action, occurred_at) index, which also holds the id: the scan stops at this flag's
        // latest switch without sorting (TASK-0055 #1).
        $context = ($connection ?? $this->db->connection())->table('audit_events')
            ->where('action', 'feature.switched')->where('context->flag', $flag)
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('context');
        $context = is_string($context) ? json_decode($context, true) : null;

        return is_array($context) && ($context['enabled'] ?? null) === true;
    }

    /**
     * Switches a flag for everyone and audits it; false when the flag already had that state (nothing is recorded).
     * Runs on the default connection, which is the migrator account only during feature:switch; on the app account the
     * database refuses (read_only). The flag's row is locked first, so concurrent switches run one after the other
     * and each audited change is a real transition (TASK-0053 #2).
     */
    public function switch(string $flag, bool $on): bool
    {
        self::known($flag);
        $writer = $this->db->connection();
        $scope = $this->pennant->serializeScope(new GlobalScope);
        try {
            return $writer->transaction(function () use ($writer, $flag, $scope, $on): bool {
                $row = $writer->table('features')->where('name', $flag)->where('scope', $scope)->lockForUpdate()->first(['id', 'value']);
                if ($row === null) {
                    throw new LogicException("Feature flag [$flag] has no stored row; its migration must create it.");
                }
                // Compared with the stored value, so switching repairs a row changed without an audit row.
                if ((json_decode((string) $row->value, flags: JSON_THROW_ON_ERROR) === true) === $on
                    && $this->lastAudited($flag, $writer) === $on) {
                    return false;
                }

                $writer->table('features')->where('id', $row->id)->update(['value' => json_encode($on), 'updated_at' => now()]);
                $this->audit->record('feature.switched', context: ['flag' => $flag, 'enabled' => $on]);

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
