<?php

namespace Modules\Evidence\Contracts;

use Carbon\CarbonImmutable;

/**
 * What other modules may know about an evidence asset its viewer can see (P1-41): identity, title, status,
 * classification, capture time and the source it came from (null for an unregistered source, PO decision O12).
 */
final readonly class EvidenceSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $title,
        public string $status,
        public string $classification,
        public CarbonImmutable $capturedAt,
        public ?int $sourceId,
    ) {}
}
