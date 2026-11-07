<?php

namespace Modules\Evidence\Events;

/**
 * Outbox event types the Evidence module publishes (P1-48, docs/03 Evidence), defined in EvidenceServiceProvider with
 * their payloads. Each is about one evidence asset (the outbox aggregate) and carries its ULID; consumers subscribe in
 * their own service provider and load what they need.
 */
final class EvidenceEvents
{
    /** The asset was withdrawn: it is kept for provenance, but no longer supports anything. */
    public const WITHDRAWN = 'evidence.withdrawn';

    /** The stored bytes of the asset no longer match their SHA-256, or are missing from the store. */
    public const INTEGRITY_FAILED = 'evidence.integrity_failed';
}
