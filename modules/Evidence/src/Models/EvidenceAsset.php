<?php

namespace Modules\Evidence\Models;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Access\Classifiable;
use Modules\Platform\Access\Scoped;
use Modules\Platform\Access\VisibleTo;
use Modules\Platform\Classification;

/**
 * One captured piece of evidence (P1-32a, docs/02 §3.5, ADR-0007). Visibility: its classification and its competitor
 * and market scopes (P1-36a, all-of) through AccessScope, and, until it is available, only its uploader and
 * evidence.hold holders see it (PO decision O32, 2026-11-03). No mass assignment; rejected or withdrawn, never deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $status
 * @property string $scan_status
 * @property ?string $scan_engine
 * @property ?CarbonInterface $scanned_at
 * @property ?string $quarantine_key
 * @property ?int $evidence_blob_id
 * @property bool $legal_hold
 * @property string $original_filename
 * @property int $size_bytes
 * @property string $title
 * @property ?string $description
 * @property ?string $source_url
 * @property ?int $source_id the source the evidence came from (P1-37); written once, at upload
 * @property ?int $source_policy_id the source's policy in force at capture (P1-37); set exactly when source_id is
 * @property CarbonInterface $captured_at
 * @property int $uploaded_by
 * @property Classification $classification
 * @property ?CarbonInterface $created_at
 */
class EvidenceAsset extends Model implements Classifiable, Scoped
{
    use VisibleTo;

    public const STATUSES = ['quarantined', 'available', 'rejected', 'withdrawn'];

    /** Readable by anyone the classification allows (docs/02 §3.5); the others only by the uploader and holders. */
    public const READABLE = ['available', 'withdrawn'];

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Assets the viewer may see: within AccessScope, and readable, or uploaded by the viewer, or the viewer holds
     * evidence.hold (O32).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function seenBy(Builder $query, Authenticatable $viewer, Gate $gate): Builder
    {
        $query->visibleTo($viewer);
        if (! $gate->forUser($viewer)->allows('evidence.hold')) {
            $query->where(fn (Builder $q) => $q->whereIn('status', self::READABLE)->orWhere('uploaded_by', $viewer->getAuthIdentifier()));
        }

        return $query;
    }

    /** @return HasMany<EvidenceCustodyEvent, $this> */
    public function custodyEvents(): HasMany
    {
        return $this->hasMany(EvidenceCustodyEvent::class);
    }

    /** @return BelongsTo<EvidenceBlob, $this> */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(EvidenceBlob::class, 'evidence_blob_id');
    }

    public function classification(): Classification
    {
        return $this->classification;
    }

    /** The competitors the asset is about (P1-36a); from the loaded relation when set (an upload's unsaved asset). */
    public function competitorIds(): array
    {
        $scopes = $this->relationLoaded('competitorScopes') ? $this->competitorScopes : $this->competitorScopes()->get();

        return array_values(array_unique(array_map(fn (EvidenceAssetCompetitor $s): int => $s->competitor_id, $scopes->all())));
    }

    /** The markets the asset is about (P1-36a); from the loaded relation when set. */
    public function marketIds(): array
    {
        $scopes = $this->relationLoaded('marketScopes') ? $this->marketScopes : $this->marketScopes()->get();

        return array_values(array_unique(array_map(fn (EvidenceAssetMarket $s): int => $s->market_id, $scopes->all())));
    }

    public static function scopeRelations(): array
    {
        return ['competitor' => ['competitorScopes', 'competitor_id'], 'market' => ['marketScopes', 'market_id']];
    }

    /** @return HasMany<EvidenceAssetCompetitor, $this> */
    public function competitorScopes(): HasMany
    {
        return $this->hasMany(EvidenceAssetCompetitor::class);
    }

    /** @return HasMany<EvidenceAssetMarket, $this> */
    public function marketScopes(): HasMany
    {
        return $this->hasMany(EvidenceAssetMarket::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification' => Classification::class,
            'captured_at' => 'datetime',
            'scanned_at' => 'datetime',
            'size_bytes' => 'integer',
            'evidence_blob_id' => 'integer',
            'legal_hold' => 'boolean',
        ];
    }
}
