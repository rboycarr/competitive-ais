<?php

namespace Modules\Intelligence\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which intelligence cites an evidence asset (P1-45: the evidence page's citing claims), as the viewer may see it:
 * items the viewer sees (IntelligenceItem::seenBy) are named; the others are counted, never named (docs/07 §3).
 */
interface IntelligenceCitations
{
    /**
     * @return array{items: list<array{ulid: string, title: string, status: string, relations: list<string>}>, hidden: int}
     */
    public function forEvidence(Authenticatable $viewer, int $assetId): array;
}
