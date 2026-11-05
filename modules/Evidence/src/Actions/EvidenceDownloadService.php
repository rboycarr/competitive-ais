<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Storage\SignedDownloads;

/**
 * Downloading evidence (P1-34, ADR-0007, docs/07 T1 and T7):
 * - needs evidence.download, and the asset must be one the viewer sees (EvidenceAsset::seenBy, the same rule as the
 *   list and detail pages); a hidden asset is "not found";
 * - only an `available` asset whose blob passed its integrity checks is served: nothing in quarantine, rejected or
 *   withdrawn (withdrawn evidence stays for provenance, not for use), nothing whose bytes are in doubt, and outside
 *   tests nothing whose stored version is unknown;
 * - the link is attachment-only, for the blob's stored version, valid `evidence.download_seconds` (at most 60);
 * - the checks, under a shared lock on the asset, the `downloaded` custody event and the audit event are one
 *   transaction, and the link is handed out only after it commits: no link without its record.
 */
class EvidenceDownloadService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
        private readonly Filesystems $storage,
    ) {}

    public function link(Authenticatable $actor, EvidenceAsset $asset): string
    {
        if (! $this->gate->forUser($actor)->allows('evidence.download')) {
            throw new AuthorizationException('You are not allowed to download evidence.');
        }
        if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($asset->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$asset->ulid]);
        }

        return $this->db->transaction(function () use ($actor, $asset): string {
            $current = EvidenceAsset::query()->sharedLock()->findOrFail($asset->id);
            $this->ensureStillSeen($actor, $current);
            $blob = $current->evidence_blob_id === null ? null : EvidenceBlob::query()->find($current->evidence_blob_id);
            if ($current->status !== 'available' || $blob === null) {
                throw ValidationException::withMessages(['download' => 'Only available evidence can be downloaded.']);
            }
            if ($blob->integrity_status !== 'ok') {
                throw ValidationException::withMessages(['download' => 'This file failed an integrity check and cannot be downloaded.']);
            }
            // Without its version, a link serves the object's latest version, not necessarily the stored one
            // (TASK-0073; ADR-0007). Only the test fakes store none.
            if ($blob->object_version_id === null && ! app()->environment('testing')) {
                throw ValidationException::withMessages(['download' => 'The stored version of this file is not recorded, so it cannot be downloaded.']);
            }

            $seconds = min(60, max(1, (int) config('evidence.download_seconds')));
            $url = SignedDownloads::url(
                $this->storage->disk($blob->storage_disk), $blob->storage_key, $blob->object_version_id,
                $current->original_filename, CarbonImmutable::now('UTC')->addSeconds($seconds),
            );

            $userId = (int) $actor->getAuthIdentifier();
            $event = new EvidenceCustodyEvent;
            $event->forceFill([
                'evidence_asset_id' => $current->id,
                'event' => 'downloaded',
                'actor_type' => 'user',
                'actor_id' => $userId,
                'occurred_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->audit->record('evidence.downloaded', $current, ['sha256' => $blob->sha256], Actor::user($userId));

            return $url;
        });
    }

    /**
     * Visibility again, under the lock (TASK-0075 #1): a scope added after the first check narrows who sees the asset.
     * Anything that adds a scope locks the asset row first (P1-36b), so it waits for this transaction or this one
     * waits for it and sees the scope.
     */
    private function ensureStillSeen(Authenticatable $actor, EvidenceAsset $current): void
    {
        if (! EvidenceAsset::seenBy(EvidenceAsset::query(), $actor, $this->gate)->whereKey($current->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(EvidenceAsset::class, [$current->ulid]);
        }
    }
}
