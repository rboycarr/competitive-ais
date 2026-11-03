<?php

namespace Modules\Platform\Database;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Which unique index a duplicate-key error came from. Code that turns a duplicate into a friendly answer ("this name
 * is taken", "already handled") must check that it is the duplicate it expects: any other unique violation in the same
 * transaction (e.g. on an audit row) is a different failure and must surface as itself, not as a validation message
 * (two unexplained "already taken" failures in parallel tests, 2026-11-03).
 *
 * MySQL 8.4 only (ADR-0003): the driver's message is "Duplicate entry '<value>' for key '<table>.<index>'". The value
 * is quoted verbatim and may itself contain "for key '…'", so only the key at the very end of the driver's own message
 * counts (TASK-0067 #1); the exception's full message (which adds the SQL) is never parsed. Anything else is not a
 * match, so an unexpected format surfaces as the original error rather than as a friendly answer.
 */
final class UniqueViolation
{
    public static function on(UniqueConstraintViolationException $e, string $table, string $index): bool
    {
        $message = $e->errorInfo[2] ?? null;

        return is_string($message) && preg_match("/ for key '([^']+)'\\z/", $message, $key) === 1 && $key[1] === "$table.$index";
    }
}
