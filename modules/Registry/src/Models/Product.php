<?php

namespace Modules\Registry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Classification;

/**
 * A product, ours or a competitor's (P1-22b, docs/02 §3.3). Visible like competitors (PO decision O25): by its own
 * classification (AccessScope; a registry entry has no competitor or market scopes), and a competitor's product only
 * while its competitor is visible as well (ProductAccess). No mass assignment; retired with a status, never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $owner_kind
 * @property ?int $competitor_id
 * @property ?int $brand_id
 * @property ?int $category_id
 * @property string $name
 * @property ?string $description
 * @property string $status
 * @property ?CarbonImmutable $launched_on
 * @property ?CarbonImmutable $discontinued_on
 * @property Classification $classification
 * @property int $created_by
 */
class Product extends Model implements Classifiable, Scoped
{
    public const STATUSES = ['active', 'discontinued', 'rumored'];

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

    /**
     * Products the viewer may see: AccessScope on the product's classification, and the competitor visible too.
     * ProductAccess::allows() is the same rule for one product.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        app(AccessScope::class)->applyTo($query, $user);

        return $query->where(fn (Builder $q) => $q->whereNull('competitor_id')
            ->orWhereIn('competitor_id', Competitor::query()->visibleTo($user)->select('id')));
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['classification' => Classification::class, 'launched_on' => 'immutable_date', 'discontinued_on' => 'immutable_date'];
    }
}
