<?php

namespace Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;

/**
 * A tracked competitor (P1-20, docs/02 §3.3). Visibility follows its classification only: a registry entry has no
 * competitor or market scopes (PO decision 2026-11-01); grants for one competitor restrict what is recorded ABOUT
 * it, not that it is tracked. No mass assignment; archived, never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property ?string $legal_name
 * @property string $slug
 * @property ?string $website
 * @property ?string $hq_country
 * @property string $lifecycle
 * @property ?string $tier
 * @property ?int $assigned_analyst_id
 * @property Classification $classification
 * @property ?string $description
 * @property int $created_by
 */
class Competitor extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public const LIFECYCLES = ['active', 'watch', 'dormant', 'archived'];

    public const TIERS = ['primary', 'secondary', 'emerging'];

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function classification(): Classification
    {
        return $this->classification;
    }

    public function competitorIds(): array
    {
        return [];
    }

    public function marketIds(): array
    {
        return [];
    }

    public static function scopeRelations(): array
    {
        return [];
    }

    /** @return HasMany<CompetitorAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(CompetitorAlias::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['classification' => Classification::class];
    }
}
