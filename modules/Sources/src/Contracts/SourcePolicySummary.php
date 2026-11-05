<?php

namespace Modules\Sources\Contracts;

use Carbon\CarbonImmutable;

/**
 * What other modules may know about a source and one approved policy version: identity, name, current status, and the
 * policy's version, decision time and retention days (P1-38; null when the policy sets none). The policy fields are
 * null when the source has no policy in force.
 */
final readonly class SourcePolicySummary
{
    public function __construct(
        public int $sourceId,
        public string $sourceUlid,
        public string $sourceName,
        public string $status,
        public ?int $policyId,
        public ?int $policyVersion,
        public ?CarbonImmutable $policyDecidedAt,
        public ?int $policyRetentionDays = null,
    ) {}
}
