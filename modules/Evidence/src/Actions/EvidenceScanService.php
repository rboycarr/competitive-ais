<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Scanning\ClamAvClient;
use Modules\Platform\Storage\LockedObjects;
use RuntimeException;
use Throwable;

/**
 * Scanning one quarantined asset and storing it once (P1-32b, ADR-0007, docs/02 §3.5; uploads are untrusted, docs/07
 * T7). Run by ScanEvidenceJob on the `evidence` queue.
 * 1. The quarantine object is copied to a private temporary file (streamed, never held in memory), and its size must
 *    match the upload.
 * 2. ClamAV scans those bytes. A virus rejects the asset; a scanner error marks the scan `error` and is retried.
 * 3. The type is detected again from the bytes, and must still match the extension. A mismatch rejects the asset
 *    outright (PO decision O34).
 * 4. Clean bytes are stored once, content-addressed at `sha256/<2>/<2>/<hash>` in the locked `evidence` bucket, with
 *    governance retention for `evidence.blob_retention_days` on that same write (PO decision O33). Under a named lock
 *    per hash, bytes already stored are not uploaded again, and `evidence_blobs` is filled by an atomic
 *    insert-or-reload on the hash.
 * 5. Under a lock on the asset row, a scan result is written only from `quarantined` with a pending scan, so a
 *    terminal result is never overwritten, and a duplicate or retried job changes nothing. The custody events and the
 *    audit record (codes and the hash only) are written in the same transaction.
 * 6. The quarantine object is deleted afterwards. A failed delete is logged as `evidence.quarantine_orphan`, for the
 *    sweep (P1-38).
 */
final class EvidenceScanService
{
    public const EVIDENCE_DISK = 'evidence';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Filesystems $storage,
        private readonly ClamAvClient $scanner,
        private readonly AuditLogger $audit,
    ) {}

    public function scan(int $assetId): void
    {
        $key = $this->claim($assetId);
        if ($key === null) {
            return; // already decided, withdrawn, or gone
        }

        $quarantine = $this->storage->disk(EvidenceUploadService::QUARANTINE_DISK);
        // Not tempnam() (CodeRulesTest's "no insecure functions"): a name this random needs no atomicity guarantee.
        $temp = sys_get_temp_dir().'/evidence-scan-'.bin2hex(random_bytes(16));
        try {
            $size = $this->stage($quarantine, $key, $temp, $assetId);

            if ($this->scanFile($temp) === ClamAvClient::INFECTED) {
                $this->decide($assetId, 'infected', null);
                $this->removeQuarantine($quarantine, $key);

                return;
            }

            $type = EvidenceUploadService::detectedType($temp);
            $asset = EvidenceAsset::query()->findOrFail($assetId);
            /** @var array<string, list<string>> $types */
            $types = (array) config('evidence.types');
            $extension = strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION));
            if (! isset($types[$type]) || ! in_array($extension, $types[$type], true)) {
                $this->decide($assetId, 'type_mismatch', null);
                $this->removeQuarantine($quarantine, $key);

                return;
            }

            $this->decide($assetId, 'clean', $this->store($temp, $size, $type));
            $this->removeQuarantine($quarantine, $key);
        } catch (Throwable $e) {
            $this->markError($assetId, $e);

            throw $e; // the queue retries (ScanEvidenceJob)
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * Under a lock: the quarantine key of an asset that still waits for its scan. A failed earlier scan (`error`)
     * becomes `pending` again here; anything already decided returns null.
     */
    private function claim(int $assetId): ?string
    {
        return $this->db->transaction(function () use ($assetId): ?string {
            $asset = EvidenceAsset::query()->lockForUpdate()->find($assetId);
            if ($asset === null || $asset->status !== 'quarantined' || $asset->quarantine_key === null) {
                return null;
            }
            if ($asset->scan_status === 'error') {
                $asset->forceFill(['scan_status' => 'pending', 'scanned_at' => null])->save();
            }

            return $asset->quarantine_key;
        });
    }

    /** Copies the quarantine object to $temp, streamed, and checks its size against the upload. */
    private function stage(Filesystem $quarantine, string $key, string $temp, int $assetId): int
    {
        $input = $quarantine->readStream($key);
        $output = fopen($temp, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            throw new RuntimeException('The quarantine object could not be read.');
        }
        try {
            $copied = stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
        $expected = (int) EvidenceAsset::query()->whereKey($assetId)->value('size_bytes');
        if ($copied === false || $copied !== $expected) {
            throw new RuntimeException('The quarantine object does not have the uploaded size.');
        }

        return $copied;
    }

    /** @return ClamAvClient::CLEAN|ClamAvClient::INFECTED */
    private function scanFile(string $path): string
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('The file to scan could not be opened.');
        }
        try {
            return $this->scanner->scan($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Stores clean bytes once and returns the blob's ID (an existing blob for the same hash is reused). A MySQL named
     * lock per hash serialises scans of identical bytes, so only one of them writes the object: without it, two could
     * both miss the row and each leave a retained version in the locked bucket.
     */
    private function store(string $path, int $size, string $type): int
    {
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new RuntimeException('The file could not be hashed.');
        }

        $connection = $this->db->connection();
        $lock = self::blobLockName($sha256);
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, (int) config('evidence.blob_lock_seconds')]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            throw new RuntimeException('Another scan is still storing the same bytes.');
        }
        try {
            return $this->storeLocked($path, $size, $type, $sha256);
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** MySQL lock names are limited to 64 characters; a hash prefix this long cannot collide in practice. */
    public static function blobLockName(string $sha256): string
    {
        return 'evidence-blob:'.substr($sha256, 0, 48);
    }

    private function storeLocked(string $path, int $size, string $type, string $sha256): int
    {
        $existing = EvidenceBlob::query()->where('sha256', $sha256)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $key = 'sha256/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
        $now = CarbonImmutable::now('UTC');
        $until = $now->addDays((int) config('evidence.blob_retention_days'));
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('The file could not be opened for storage.');
        }
        try {
            // Written before the row: a concurrent copy of the same bytes writes identical content (ADR-0007).
            $version = LockedObjects::write($this->storage->disk(self::EVIDENCE_DISK), $key, $stream, $type, $until);
        } finally {
            fclose($stream);
        }

        // Atomic insert-or-reload on the hash: two scans of the same bytes end with one row.
        $connection = $this->db->connection();
        $connection->statement(
            'INSERT INTO evidence_blobs (sha256, storage_disk, storage_key, object_version_id, size_bytes, detected_mime, first_scan_engine, first_scanned_at, retention_until, legal_hold, integrity_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$sha256, self::EVIDENCE_DISK, $key, $version, $size, $type, (string) config('services.clamav.engine'), $now->format('Y-m-d H:i:s.u'), $until->toDateString(), 'ok', $now->format('Y-m-d H:i:s.u')],
        );

        return (int) $connection->getPdo()->lastInsertId();
    }

    /**
     * Under a lock on the asset: records the result, only if the asset still waits for it.
     *
     * @param  'clean'|'infected'|'type_mismatch'  $result
     */
    private function decide(int $assetId, string $result, ?int $blobId): void
    {
        $this->db->transaction(function () use ($assetId, $result, $blobId): void {
            $asset = EvidenceAsset::query()->lockForUpdate()->find($assetId);
            if ($asset === null || $asset->status !== 'quarantined' || $asset->scan_status !== 'pending') {
                return; // a terminal result is never overwritten
            }
            $now = CarbonImmutable::now('UTC');
            $asset->forceFill([
                'status' => $result === 'clean' ? 'available' : 'rejected',
                'scan_status' => $result === 'infected' ? 'infected' : 'clean',
                'scan_engine' => (string) config('services.clamav.engine'),
                'scanned_at' => $now,
                'quarantine_key' => null,
                'evidence_blob_id' => $blobId,
            ])->save();

            $this->custody($assetId, 'scanned', $now);
            if ($result === 'clean') {
                $sha256 = (string) EvidenceBlob::query()->whereKey((int) $blobId)->value('sha256');
                $this->audit->record('evidence.scanned', $asset, ['result' => 'clean', 'sha256' => $sha256], Actor::system());
            } else {
                $this->custody($assetId, 'rejected', $now);
                $this->audit->record('evidence.rejected', $asset, ['reason' => $result], Actor::system());
            }
        });
    }

    private function custody(int $assetId, string $event, CarbonImmutable $at): void
    {
        $row = new EvidenceCustodyEvent;
        $row->forceFill(['evidence_asset_id' => $assetId, 'event' => $event, 'actor_type' => 'system', 'actor_id' => null, 'occurred_at' => $at])->save();
    }

    /** A failed attempt: retryable `error`, only from a pending scan. Logged with the asset and error class only. */
    private function markError(int $assetId, Throwable $error): void
    {
        try {
            $this->db->transaction(function () use ($assetId): void {
                $asset = EvidenceAsset::query()->lockForUpdate()->find($assetId);
                if ($asset !== null && $asset->status === 'quarantined' && $asset->scan_status === 'pending') {
                    $asset->forceFill(['scan_status' => 'error', 'scanned_at' => CarbonImmutable::now('UTC')])->save();
                }
            });
        } finally {
            Log::warning('evidence.scan_error', ['asset_id' => $assetId, 'error' => $error::class]);
        }
    }

    private function removeQuarantine(Filesystem $quarantine, string $key): void
    {
        try {
            if ($quarantine->delete($key) === false) {
                throw new RuntimeException('The quarantine object was not deleted.');
            }
        } catch (Throwable $e) {
            Log::warning('evidence.quarantine_orphan', ['quarantine_key' => $key, 'cleanup_error' => $e::class]);
        }
    }
}
