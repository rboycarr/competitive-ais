<?php

namespace Modules\Platform\Access;

use Modules\Platform\Classification;

/**
 * A user's effective access grants, already filtered (active, held directly or through a team) and reduced to what
 * visibility needs: the highest classification each scope opens. Scope `all` covers everything up to its level;
 * a competitor grant covers that competitor (P1-20a). Market grants follow the market registry (P1-23).
 */
final readonly class GrantSet
{
    /**
     * @param  array<int, Classification>  $competitors  competitor ID => highest classification granted for it
     */
    public function __construct(
        public ?Classification $all,
        public array $competitors = [],
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    public function coversAll(Classification $classification): bool
    {
        return $this->all !== null && $this->all->isAtLeast($classification);
    }

    /**
     * Competitor IDs whose grants open this classification (not counting the `all` grant).
     *
     * @return list<int>
     */
    public function competitorsCovering(Classification $classification): array
    {
        return array_keys(array_filter($this->competitors, fn (Classification $granted): bool => $granted->isAtLeast($classification)));
    }

    /**
     * Whether the grants cover a record of this classification about these competitors and markets: every one of
     * them (all-of, docs/07 §3). An unscoped record is covered only by an `all` grant (the explicit grant a
     * restricted record needs).
     *
     * @param  list<int>  $competitorIds
     * @param  list<int>  $marketIds
     */
    public function covers(Classification $classification, array $competitorIds = [], array $marketIds = []): bool
    {
        if ($this->coversAll($classification)) {
            return true;
        }
        if ($competitorIds === [] || $marketIds !== []) {
            return false; // unscoped, or about a market: only `all` grants exist for those yet
        }

        return array_diff($competitorIds, $this->competitorsCovering($classification)) === [];
    }
}
