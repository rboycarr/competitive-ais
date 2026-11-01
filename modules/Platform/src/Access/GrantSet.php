<?php

namespace Modules\Platform\Access;

use Modules\Platform\Classification;

/**
 * A user's effective access grants, already filtered (active, held directly or through a team) and reduced to what
 * visibility needs: the highest classification each scope opens. Only scope `all` exists until the registry adds
 * competitors and markets (P1-14 note in docs/02); typed scopes extend covers() then.
 */
final readonly class GrantSet
{
    public function __construct(public ?Classification $all) {}

    public static function none(): self
    {
        return new self(null);
    }

    /**
     * Whether the grants cover a record of this classification about these competitors and markets (all of them).
     *
     * @param  list<int>  $competitorIds
     * @param  list<int>  $marketIds
     */
    public function covers(Classification $classification, array $competitorIds = [], array $marketIds = []): bool
    {
        return $this->all !== null && $this->all->isAtLeast($classification);
    }
}
