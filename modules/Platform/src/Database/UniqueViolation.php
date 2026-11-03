<?php

namespace Modules\Platform\Database;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Which unique index a duplicate-key error came from. Code that turns a duplicate into a friendly answer ("this name
 * is taken", "already handled") must check that it is the duplicate it expects: any other unique violation in the same
 * transaction (e.g. on an audit row) is a different failure and must surface as itself, not as a validation message
 * (two unexplained "already taken" failures in parallel tests, 2026-11-03).
 *
 * MySQL names the index in its message: "Duplicate entry '…' for key 'table.index'" (8.0.19 and later; earlier
 * versions give the index alone).
 */
final class UniqueViolation
{
    public static function on(UniqueConstraintViolationException $e, string $table, string $index): bool
    {
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        return preg_match("/for key '([^']+)'/", $message, $key) === 1 && in_array($key[1], ["$table.$index", $index], true);
    }
}
