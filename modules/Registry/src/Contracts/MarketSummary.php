<?php

namespace Modules\Registry\Contracts;

/** What other modules may know about a market: its identity, code and name. */
final readonly class MarketSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $code,
        public string $name,
    ) {}
}
