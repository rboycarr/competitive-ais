<?php

namespace Modules\Evidence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content scanned clean once and stored once, content-addressed by its hash (P1-32b, docs/02 §3.5, ADR-0007). A blob
 * is created only by `EvidenceScanService`'s insert-or-reload on `sha256`; it has no visibility of its own and is
 * read only through the assets that reference it. No mass assignment; retained while referenced, never deleted by
 * the app account (grant class `no_delete`). Retention (the latest of its assets) and hold (OR of its assets) are kept
 * in step by EvidenceBlobReconciler (P1-38), whose `deletable()` any future deleting job must pass (PO decision O38).
 *
 * @property int $id
 * @property string $sha256
 * @property string $storage_disk
 * @property string $storage_key
 * @property ?string $object_version_id
 * @property int $size_bytes
 * @property string $detected_mime
 * @property string $first_scan_engine
 * @property CarbonInterface $first_scanned_at
 * @property ?CarbonInterface $retention_until
 * @property bool $legal_hold
 * @property string $integrity_status
 * @property ?CarbonInterface $last_verified_at
 * @property CarbonInterface $created_at
 */
class EvidenceBlob extends Model
{
    public const INTEGRITY_STATUSES = ['ok', 'mismatch'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return HasMany<EvidenceAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(EvidenceAsset::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'first_scanned_at' => 'datetime',
            'retention_until' => 'date',
            'legal_hold' => 'boolean',
            'last_verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
