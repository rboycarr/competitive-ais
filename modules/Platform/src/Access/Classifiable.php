<?php

namespace Modules\Platform\Access;

use Modules\Platform\Classification;

/**
 * A record with a classification (docs/07 §3). Its table stores it in a `classification` column, which
 * AccessScope::applyTo() filters on.
 */
interface Classifiable
{
    public function classification(): Classification;
}
