<?php

namespace Modules\Registry\Support;

/**
 * One normal form for registry names that must be unique (aliases, brands, categories): trimmed, with whitespace,
 * separators and control characters collapsed to single ASCII spaces. Each table's CHECK holds the same form
 * (TASK-0034 #1), and its utf8mb4_0900_ai_ci collation makes the UNIQUE index ignore case and accents.
 */
final class Names
{
    public static function normalize(string $name): string
    {
        return trim((string) preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $name));
    }
}
