<?php

namespace Modules\Platform\Outbox;

use InvalidArgumentException;
use Modules\Platform\Support\TypedFields;

/**
 * Registry of outbox event types, their payload schemas and their handlers (P1-06). Modules register in their
 * service provider:
 *
 *   $events = $this->app->make(OutboxEvents::class);
 *   $events->define('intelligence.published', ['item_ulid' => 'ulid', 'version' => 'int']);
 *   $events->subscribe('intelligence.published', IndexIntelligenceItem::class);
 *
 * Payloads carry IDs and minimal data, never classified content (docs/02 §3.1): free text is not an allowed type.
 * Consumers load what they need and apply authorization themselves.
 */
class OutboxEvents
{
    private const EVENT_TYPE = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const PAYLOAD_TYPES = ['ulid', 'id', 'int', 'bool', 'code', 'sha256'];

    /** @var array<string, array<string, string>> */
    private array $schemas = [];

    /** @var array<string, list<class-string<OutboxHandler>>> */
    private array $handlers = [];

    /**
     * @param  array<string, string>  $payload  key => type
     */
    public function define(string $eventType, array $payload = []): void
    {
        if (strlen($eventType) > 100 || ! preg_match(self::EVENT_TYPE, $eventType)) {
            throw new InvalidArgumentException("Invalid outbox event type [$eventType]; use dotted lower_snake_case, e.g. intelligence.published.");
        }
        TypedFields::assertSchema("outbox event [$eventType]", $payload, self::PAYLOAD_TYPES);
        if (isset($this->schemas[$eventType]) && $this->schemas[$eventType] !== $payload) {
            throw new InvalidArgumentException("Outbox event [$eventType] is already defined with another payload.");
        }

        $this->schemas[$eventType] = $payload;
    }

    /**
     * @param  class-string<OutboxHandler>  $handler
     */
    public function subscribe(string $eventType, string $handler): void
    {
        $this->payload($eventType);
        if (! is_subclass_of($handler, OutboxHandler::class) || strlen($handler) > 150) {
            throw new InvalidArgumentException("[$handler] must implement ".OutboxHandler::class.' and have a name of at most 150 characters.');
        }
        if (! in_array($handler, $this->handlers[$eventType] ?? [], true)) {
            $this->handlers[$eventType][] = $handler;
        }
    }

    /**
     * @return array<string, string> key => type
     */
    public function payload(string $eventType): array
    {
        return $this->schemas[$eventType]
            ?? throw new InvalidArgumentException("Outbox event [$eventType] is not defined; register it with OutboxEvents::define().");
    }

    /**
     * @return list<class-string<OutboxHandler>>
     */
    public function handlers(string $eventType): array
    {
        return $this->handlers[$eventType] ?? [];
    }
}
