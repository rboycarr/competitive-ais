<?php

namespace Modules\Sources\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;

/**
 * A source of evidence (P1-30, docs/02 §3.4, docs/04 §2). Visibility follows its classification only: a source has no
 * competitor or market scopes. No mass assignment; retired, never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $source_type
 * @property ?string $base_url
 * @property ?string $host
 * @property string $method
 * @property string $status
 * @property ?int $current_policy_id the approved policy in force (P1-31a)
 * @property string $default_reliability
 * @property int $owner_id
 * @property int $proposed_by
 * @property Classification $classification
 * @property ?string $description
 * @property ?CarbonInterface $created_at
 */
class Source extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public const TYPES = ['website', 'rss', 'api', 'licensed_feed', 'public_filing', 'social', 'internal', 'field', 'other'];

    /** In order of preference (docs/04 §1); only `manual` while automated collection is off. */
    public const METHODS = ['manual', 'rss', 'api', 'http_fetch', 'browser'];

    public const STATUSES = ['proposed', 'policy_review', 'approved', 'suspended', 'retired'];

    /** Admiralty source reliability, A (completely reliable) to F (cannot be judged) (ADR-0010). */
    public const RELIABILITIES = ['A', 'B', 'C', 'D', 'E', 'F'];

    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The policy decisions on this source (P1-31a). A policy has no visibility of its own: it is read only through a
     * source the viewer can see, so whoever sees the source sees its whole history (TASK-0059 #3). A policy-level scope
     * would need its own check here.
     *
     * @return HasMany<SourcePolicy, $this>
     */
    public function policies(): HasMany
    {
        return $this->hasMany(SourcePolicy::class);
    }

    /**
     * Suspensions, reinstatements and retirements (P1-31b), visible like the policies: only through a visible source.
     *
     * @return HasMany<SourceStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(SourceStatusChange::class);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['classification' => Classification::class];
    }
}
