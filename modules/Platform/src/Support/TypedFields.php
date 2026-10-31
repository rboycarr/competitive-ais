<?php

namespace Modules\Platform\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Typed key/value schemas for data that is stored as JSON and must stay minimal: audit context (P1-05) and
 * outbox payloads (P1-06). A schema maps keys to types; append [] for a list of at most 100.
 *
 *   ulid    a ULID                    id    a positive integer      int   an integer        bool  true or false
 *   code    lower-case identifier, e.g. an enum value (max 64)      sha256  a lower-case hex SHA-256 digest
 *   text    short free text (max 500), refused if it looks like a credential (TASK-0010 #1); callers record IDs,
 *           codes and digests, never user input, prompts or document content
 *
 * No floats or nested structures, so values survive MySQL's JSON round trip unchanged (row hashes depend on it).
 * Keys that look like secrets or prompts cannot be part of a schema.
 */
final class TypedFields
{
    public const TYPES = ['ulid', 'id', 'int', 'bool', 'code', 'sha256', 'text'];

    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    private const FORBIDDEN_KEY = '/pass|secret|token|key|prompt|credential|cookie|authorization|otp|totp/';

    private const MAX_TEXT = 500;

    private const MAX_LIST = 100;

    /** A safety net only; see the class comment. */
    private const CREDENTIAL_SHAPES = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b(?:gh[pousr]_|github_pat_|sk-|xox[abprs]-|AKIA|ASIA|AIza)[A-Za-z0-9_\-]{8,}/',
        '/\bBearer\s+\S{8,}/i',
        '/\b(?:password|passwd|pwd|secret|token|api[_-]?key)\s*[:=]\s*\S*/i', // the value too, so redact() masks it
        '/[A-Za-z0-9+\/_\-]{32,}={0,2}/',
    ];

    /**
     * @param  array<string, string>  $schema  key => type
     * @param  list<string>  $types  the types this kind of data may use
     */
    public static function assertSchema(string $owner, array $schema, array $types = self::TYPES): void
    {
        foreach ($schema as $key => $type) {
            if (! preg_match(self::KEY, $key)) {
                throw new InvalidArgumentException("Invalid key [$key] for $owner.");
            }
            if (preg_match(self::FORBIDDEN_KEY, $key)) {
                throw new InvalidArgumentException("Key [$key] for $owner looks like a secret or prompt; these are never stored.");
            }
            if (! preg_match('/^[a-z0-9]+(\[\])?$/', $type) || ! in_array(rtrim($type, '[]'), $types, true)) {
                throw new InvalidArgumentException("Unknown type [$type] for key [$key] of $owner; use one of ".implode(', ', $types).' (optionally with []).');
            }
        }
    }

    /**
     * @param  array<string, string>  $schema  key => type
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null the values, or null when there are none
     */
    public static function validate(string $owner, array $schema, array $values): ?array
    {
        foreach ($values as $key => $value) {
            $type = $schema[$key] ?? throw new InvalidArgumentException("Key [$key] is not allowed for $owner.");
            $isList = str_ends_with($type, '[]');
            if ($isList && (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_LIST)) {
                throw new InvalidArgumentException("Value [$key] for $owner must be a list of at most ".self::MAX_LIST." $type values.");
            }
            foreach ($isList ? $value : [$value] as $item) {
                if (! self::matches($item, rtrim($type, '[]'))) {
                    throw new InvalidArgumentException("Value [$key] for $owner is not a valid $type.");
                }
            }
        }

        return $values === [] ? null : $values;
    }

    public static function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'ulid' => is_string($value) && Str::isUlid($value),
            'sha256' => is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1,
            'id' => is_int($value) && $value > 0,
            'int' => is_int($value),
            'bool' => is_bool($value),
            'code' => is_string($value) && preg_match('/^[a-z0-9][a-z0-9_.\-]{0,63}$/', $value) === 1,
            'text' => is_string($value) && mb_strlen($value) <= self::MAX_TEXT && ! self::looksLikeCredential($value),
            default => false,
        };
    }

    /**
     * Replaces credential-shaped parts of a diagnostic message with *** (TASK-0012 #2). Heuristic, like the check
     * above: use it for log lines, never as permission to store raw messages.
     */
    public static function redact(string $message): string
    {
        return (string) preg_replace(self::CREDENTIAL_SHAPES, '***', $message);
    }

    private static function looksLikeCredential(string $value): bool
    {
        foreach (self::CREDENTIAL_SHAPES as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
