<?php

namespace Modules\Platform\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Feature flags (docs/01, docs/10): read from config/features.php, off unless set to exactly true. An unknown flag is
 * a programming error, not "off", so a typo cannot quietly disable a check. Kept in configuration for Phase 1 (no
 * runtime toggle); a database-backed store (Pennant, docs/10) can replace this class without changing its callers.
 */
final class Features
{
    public const COLLECTION = 'collection';

    private const KNOWN = [self::COLLECTION];

    public function __construct(private readonly Repository $config) {}

    public function enabled(string $flag): bool
    {
        if (! in_array($flag, self::KNOWN, true)) {
            throw new InvalidArgumentException("Unknown feature flag [$flag].");
        }

        return $this->config->get("features.$flag") === true;
    }
}
