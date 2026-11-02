<?php

namespace Modules\Registry\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Calendar dates entered in registry forms (relationship periods, product launch dates): YYYY-MM-DD, 1800–2199. */
final class Dates
{
    /** Null for an empty value; a validation error on the field for anything that is not a real date in range. */
    public static function parse(?string $value, string $field): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1800 || (int) $m[1] > 2199) {
            throw ValidationException::withMessages([$field => 'Enter a date between 1800 and 2199 as YYYY-MM-DD.']);
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }
}
