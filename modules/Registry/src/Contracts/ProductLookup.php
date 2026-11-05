<?php

namespace Modules\Registry\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Products for other modules (docs/03 Registry), always within what the viewer may see (O25: the product's
 * classification, and a competitor's product only while its competitor is visible), e.g. the subjects of an
 * intelligence item (P1-40). Returns summaries, never the model.
 */
interface ProductLookup
{
    public function findVisible(Authenticatable $viewer, string $ulid): ?ProductSummary;

    /**
     * Every product the viewer can see, ordered by name.
     *
     * @return list<ProductSummary>
     */
    public function visibleTo(Authenticatable $viewer): array;

    /**
     * Summaries by ID for the products among them the viewer can see; the others are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, ProductSummary>
     */
    public function summaries(Authenticatable $viewer, array $ids): array;
}
