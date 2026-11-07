<?php

namespace Modules\Evidence\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Evidence for provenance views (P1-45, docs/03 Intelligence): the assets a viewer can see, as on the evidence pages
 * (evidence.view, then EvidenceAsset::seenBy), with their content hash, capture policy and custody trail. An asset the
 * viewer cannot see is left out, like one that does not exist: the caller shows it as hidden.
 */
interface EvidenceProvenance
{
    /**
     * @param  list<int>  $ids
     * @return array<int, EvidenceTrace>
     */
    public function traces(Authenticatable $viewer, array $ids): array;
}
