<?php

namespace Modules\Intelligence\Contracts;

/**
 * What other modules may ask about references to their records from intelligence (P1-40), e.g. the Registry's archive
 * guard (docs/03 Registry). Counts every item, whoever can see it; answers yes or no, never which items.
 */
interface IntelligenceReferences
{
    /** Whether an open item (not yet published, rejected or otherwise decided) is about this competitor. */
    public function hasOpenItemsAbout(int $competitorId): bool;
}
