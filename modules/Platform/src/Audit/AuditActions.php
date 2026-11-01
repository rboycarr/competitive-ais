<?php

namespace Modules\Platform\Audit;

use InvalidArgumentException;
use Modules\Platform\Support\TypedFields;

/**
 * Registry of audited actions and the typed context keys each may carry (ADR-0006: context is allowlisted per
 * action). Modules define their actions in their service provider:
 *
 *   $this->app->make(AuditActions::class)->define('evidence.downloaded', ['asset_ulid' => 'ulid', 'reason' => 'code']);
 *
 * Types and limits: TypedFields. Recording an undefined action, an unlisted key or a value of the wrong type fails.
 */
class AuditActions
{
    private const ACTION = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

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
        TypedFields::assertSchema("audit action [$action]", $context);
        if (isset($this->actions[$action]) && $this->actions[$action] !== $context) {
            throw new InvalidArgumentException("Audit action [$action] is already defined with other context keys.");
        }

        $this->actions[$action] = $context;
    }

    /**
     * Every defined action, sorted (the audit viewer's action filter).
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->actions);
        sort($names);

        return $names;
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
