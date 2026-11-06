<?php

namespace Modules\Sources\Contracts;

/**
 * What other modules may show about a source next to evidence it supplied (P1-41): identity, name, current status and
 * its default reliability grade (A–F, ADR-0010), which the analyst sees beside a claim's own rating.
 */
final readonly class SourceSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $name,
        public string $status,
        public string $defaultReliability,
    ) {}
}
