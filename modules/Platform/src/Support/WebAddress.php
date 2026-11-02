<?php

namespace Modules\Platform\Support;

/**
 * One rule for web addresses that people type in (TASK-0031 #3): a valid URL with an http or https scheme and a host.
 * The app stores and links these addresses; it never fetches them (Phase 3 collection has its own SSRF controls,
 * docs/04 §4).
 */
final class WebAddress
{
    public static function isValid(string $value): bool
    {
        $parts = filter_var($value, FILTER_VALIDATE_URL) === false ? false : parse_url($value);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
    }

    /** True when the address carries a user name or password ("https://user:secret@host"). */
    public static function hasCredentials(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
    }

    /**
     * The host of a valid address as a lower-case ASCII domain name (internationalized names in punycode, no trailing
     * dot), or null when it is not a domain name: IP literals, single labels such as "localhost", and anything IDNA
     * refuses.
     */
    public static function domain(string $value): ?string
    {
        if (! self::isValid($value)) {
            return null;
        }
        $host = rtrim((string) parse_url($value, PHP_URL_HOST), '.');
        $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253) {
            return null;
        }
        $ascii = strtolower($ascii);
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        // At least two labels, and a top-level label that is not all digits (that would be an IPv4 address).
        if (! preg_match("/^(?:$label\\.)+$label$/", $ascii) || ctype_digit((string) substr($ascii, (int) strrpos($ascii, '.') + 1))) {
            return null;
        }

        return $ascii;
    }
}
