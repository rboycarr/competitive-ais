<?php

namespace Modules\Platform\Audit;

use InvalidArgumentException;

/**
 * Registry of audited actions and the context keys each may carry (ADR-0006: context is allowlisted per action).
 * Modules define their actions in their service provider:
 *
 *   $this->app->make(AuditActions::class)->define('evidence.downloaded', ['asset_ulid', 'reason']);
 *
 * Recording an undefined action or an unlisted key fails. Keys that look like secrets or prompts cannot be
 * listed at all, so they never reach the append-only table.
 */
class AuditActions
{
    private const ACTION = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    private const FORBIDDEN_KEY = '/pass|secret|token|key|prompt|credential|cookie|authorization|otp|totp/';

    /** @var array<string, list<string>> */
    private array $actions = [];

    /**
     * @param  list<string>  $contextKeys
     */
    public function define(string $action, array $contextKeys = []): void
    {
        if (strlen($action) > 100 || ! preg_match(self::ACTION, $action)) {
            throw new InvalidArgumentException("Invalid audit action name [$action]; use dotted lower_snake_case, e.g. evidence.downloaded.");
        }
        foreach ($contextKeys as $key) {
            if (! preg_match(self::KEY, $key)) {
                throw new InvalidArgumentException("Invalid audit context key [$key] for [$action].");
            }
            if (preg_match(self::FORBIDDEN_KEY, $key)) {
                throw new InvalidArgumentException("Audit context key [$key] for [$action] looks like a secret or prompt; these are never stored.");
            }
        }
        if (isset($this->actions[$action]) && $this->actions[$action] !== $contextKeys) {
            throw new InvalidArgumentException("Audit action [$action] is already defined with other context keys.");
        }

        $this->actions[$action] = $contextKeys;
    }

    /**
     * @return list<string>
     */
    public function contextKeys(string $action): array
    {
        return $this->actions[$action]
            ?? throw new InvalidArgumentException("Audit action [$action] is not defined; register it with AuditActions::define().");
    }
}
