<?php

namespace Modules\Intelligence\Models;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An intelligence item (P1-40, docs/02 §3.6). Visibility (docs/07 §3): its classification and its competitor and market
 * subjects (all-of) through AccessScope; and, until it is released (published, then retracted or superseded), only its
 * author, owner and assigned reviewer, and holders of intelligence.review, see it (statusVisible). No mass assignment;
 * never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $title
 * @property string $summary Markdown source, shown only through SafeMarkdown
 * @property string $item_type
 * @property Classification $classification
 * @property bool $high_impact
 * @property string $status
 * @property int $author_id
 * @property int $owner_id
 * @property ?int $assigned_reviewer_id
 * @property ?CarbonInterface $observed_at
 * @property ?CarbonInterface $published_at published by the source
 * @property ?CarbonInterface $collected_at
 * @property ?CarbonInterface $submitted_at when the current submission was made (P1-43: the queue's age)
 * @property ?CarbonInterface $verified_at when a reviewer approved it (P1-43)
 * @property ?CarbonInterface $released_at published here (P1-44)
 * @property int $version
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class IntelligenceItem extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public const TYPES = ['news', 'product_change', 'pricing_signal', 'strategy', 'leadership', 'partnership', 'regulatory', 'facility', 'financial', 'other'];

    public const STATUSES = ['draft', 'submitted', 'in_review', 'changes_requested', 'approved', 'published', 'rejected', 'retracted', 'superseded'];

    /** Content (title, summary, subjects, claims) changes only in these (docs/03 Intelligence). */
    public const EDITABLE = ['draft', 'changes_requested'];

    /** Readable by anyone AccessScope allows; the others only per statusVisible. */
    public const RELEASED = ['published', 'retracted', 'superseded'];

    /** In the verification queue (P1-43): waiting for a reviewer, or being reviewed. */
    public const IN_QUEUE = ['submitted', 'in_review'];

    /** Not yet decided: a competitor they are about cannot be archived (docs/03 Registry). */
    public const OPEN = ['draft', 'submitted', 'in_review', 'changes_requested', 'approved'];

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Items the viewer may see: within AccessScope, and released, or the viewer is the author, owner or assigned
     * reviewer, or holds intelligence.review (statusVisible, docs/07 §3). canBeSeenBy() is the same rule for one item.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function seenBy(Builder $query, Authenticatable $viewer, Gate $gate): Builder
    {
        $query->visibleTo($viewer);
        if (! $gate->forUser($viewer)->allows('intelligence.review')) {
            $id = $viewer->getAuthIdentifier();
            $query->where(fn (Builder $q) => $q->whereIn('status', self::RELEASED)
                ->orWhere('author_id', $id)->orWhere('owner_id', $id)->orWhere('assigned_reviewer_id', $id));
        }

        return $query;
    }

    public function canBeSeenBy(Authenticatable $viewer, Gate $gate, AccessScope $access): bool
    {
        if (! $access->allows($viewer, $this)) {
            return false;
        }
        $id = (int) $viewer->getAuthIdentifier();

        return in_array($this->status, self::RELEASED, true)
            || in_array($id, [$this->author_id, $this->owner_id, $this->assigned_reviewer_id], true)
            || $gate->forUser($viewer)->allows('intelligence.review');
    }

    /** A hidden item does not exist for this viewer (docs/07 §3): 404. */
    public function ensureSeenBy(Authenticatable $viewer, Gate $gate, AccessScope $access): void
    {
        if (! $this->canBeSeenBy($viewer, $gate, $access)) {
            throw new NotFoundHttpException;
        }
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function classification(): Classification
    {
        return $this->classification;
    }

    /** The competitors the item is about; from the loaded relation when set (an unsaved item being checked). */
    public function competitorIds(): array
    {
        $rows = $this->relationLoaded('competitorSubjects') ? $this->competitorSubjects : $this->competitorSubjects()->get();

        return array_values(array_unique(array_map(fn (IntelligenceItemCompetitor $s): int => $s->competitor_id, $rows->all())));
    }

    /** The markets the item is about; from the loaded relation when set. */
    public function marketIds(): array
    {
        $rows = $this->relationLoaded('marketSubjects') ? $this->marketSubjects : $this->marketSubjects()->get();

        return array_values(array_unique(array_map(fn (IntelligenceItemMarket $s): int => $s->market_id, $rows->all())));
    }

    /** @return list<int> */
    public function productIds(): array
    {
        $rows = $this->relationLoaded('productSubjects') ? $this->productSubjects : $this->productSubjects()->get();

        return array_values(array_unique(array_map(fn (IntelligenceItemProduct $s): int => $s->product_id, $rows->all())));
    }

    public static function scopeRelations(): array
    {
        return ['competitor' => ['competitorSubjects', 'competitor_id'], 'market' => ['marketSubjects', 'market_id']];
    }

    /** @return HasMany<IntelligenceItemCompetitor, $this> */
    public function competitorSubjects(): HasMany
    {
        return $this->hasMany(IntelligenceItemCompetitor::class);
    }

    /** @return HasMany<IntelligenceItemMarket, $this> */
    public function marketSubjects(): HasMany
    {
        return $this->hasMany(IntelligenceItemMarket::class);
    }

    /** @return HasMany<IntelligenceItemProduct, $this> */
    public function productSubjects(): HasMany
    {
        return $this->hasMany(IntelligenceItemProduct::class);
    }

    /** @return HasMany<IntelligenceClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(IntelligenceClaim::class);
    }

    /** @return HasMany<VerificationReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(VerificationReview::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification' => Classification::class,
            'high_impact' => 'boolean',
            'author_id' => 'integer',
            'owner_id' => 'integer',
            'assigned_reviewer_id' => 'integer',
            'version' => 'integer',
            'observed_at' => 'datetime',
            'published_at' => 'datetime',
            'collected_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
