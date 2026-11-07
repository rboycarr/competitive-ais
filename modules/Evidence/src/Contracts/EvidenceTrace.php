<?php

namespace Modules\Evidence\Contracts;

use Carbon\CarbonImmutable;

/**
 * What a provenance view may show about an evidence asset its viewer can see (P1-45): the asset, its stored content
 * (SHA-256, size, type, integrity; null while it has none, e.g. in quarantine or after withdrawal), the source and policy
 * version recorded at capture, and its custody trail, oldest first.
 */
final readonly class EvidenceTrace
{
    /**
     * @param  list<array{event: string, actorId: ?int, occurredAt: CarbonImmutable}>  $custody
     */
    public function __construct(
        public int $id,
        public string $ulid,
        public string $title,
        public string $status,
        public string $classification,
        public CarbonImmutable $capturedAt,
        public ?string $sha256,
        public ?int $sizeBytes,
        public ?string $detectedMime,
        public ?string $integrityStatus,
        public ?int $sourceId,
        public ?int $sourcePolicyId,
        public int $uploadedBy,
        public array $custody,
    ) {}
}
