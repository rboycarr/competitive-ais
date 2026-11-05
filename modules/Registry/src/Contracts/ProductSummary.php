<?php

namespace Modules\Registry\Contracts;

/** What other modules may know about a product they can see: its identity, name and owner (null: our own). */
final readonly class ProductSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $name,
        public ?int $competitorId,
    ) {}
}
