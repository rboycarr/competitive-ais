<?php

namespace Modules\Platform\Audit;

use InvalidArgumentException;

/**
 * Registry of audited actions and the typed context keys each may carry (ADR-0006: context is allowlisted per
 * action). Modules define their actions in their service provider:
 *
 *   $this->app->make(AuditActions::class)->define('evidence.downloaded', ['asset_ulid' => 'ulid', 'reason' => 'code']);
 *
 * Types (append [] for a list of at most 100):
 *   ulid  a ULID                      id    a positive integer        int   an integer
 *   bool  true or false               code  lower-case identifier, e.g. an enum value (max 64)
 *   sha256  a lower-case hex SHA-256 digest, e.g. an evidence fingerprint (TASK-0011)
 *   text  short free text (max 500); refused if it looks like a credential (AuditLogger). Never pass user input,
 *         request bodies, prompts or document content: record IDs and codes instead (TASK-0010 #1).
 *
 * Recording an undefined action, an unlisted key or a value of the wrong type fails. Keys that look like secrets
 * or prompts cannot be listed at all.
 */
class AuditActions
{
    public const TYPES = ['ulid', 'id', 'int', 'bool', 'code', 'sha256', 'text'];

    private const ACTION = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    private const FORBIDDEN_KEY = '/pass|secret|token|key|prompt|credential|cookie|authorization|otp|totp/';

    /** @var array<string, array<string, string>> */
    private array $actions = [];

    /**
     * @param  array<string, string>  $context  key => type
     */
    public function define(string $action, array $context = []): void
    {
        if (strlen($action) > 100 || ! preg_match(self::ACTION, $action)) {
            throw new InvalidArgumentException("Invalid audit action name [$action]; use dotted lower_snake_case, e.g. evidence.downloaded.");
        }
        foreach ($context as $key => $type) {
            if (! preg_match(self::KEY, $key)) {
                throw new InvalidArgumentException("Invalid audit context key [$key] for [$action].");
            }
            if (preg_match(self::FORBIDDEN_KEY, $key)) {
                throw new InvalidArgumentException("Audit context key [$key] for [$action] looks like a secret or prompt; these are never stored.");
            }
            if (! in_array(rtrim($type, '[]'), self::TYPES, true) || ! preg_match('/^[a-z0-9]+(\[\])?$/', $type)) {
                throw new InvalidArgumentException("Unknown type [$type] for audit context key [$key] of [$action]; use one of ".implode(', ', self::TYPES).' (optionally with []).');
            }
        }
        if (isset($this->actions[$action]) && $this->actions[$action] !== $context) {
            throw new InvalidArgumentException("Audit action [$action] is already defined with other context keys.");
        }

        $this->actions[$action] = $context;
    }

    /**
     * @return array<string, string> key => type
     */
    public function context(string $action): array
    {
        return $this->actions[$action]
            ?? throw new InvalidArgumentException("Audit action [$action] is not defined; register it with AuditActions::define().");
    }
}
