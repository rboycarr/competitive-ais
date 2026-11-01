<?php

namespace Modules\Registry\Contracts;

/** What other modules may know about a competitor they can see: its identity and name. */
final readonly class CompetitorSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $name,
    ) {}
}
