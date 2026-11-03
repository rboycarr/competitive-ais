<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Scanning\ClamAvClient;
use RuntimeException;
use Throwable;

final class EvidenceScanService
{
    public const EVIDENCE_DISK = 'evidence';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Filesystems $storage,
        private readonly ClamAvClient $clam,
        private readonly AuditLogger $audit,
    ) {}

    public function scan(int $assetId): void
    {
        $asset = EvidenceAsset::query()->find($assetId);
        if ($asset === null || in_array($asset->status, ['available', 'rejected', 'withdrawn'], true)) {
            return;
        }
        if ($asset->scan_status === 'error') {
            $asset->forceFill(['scan_status' => 'pending', 'scanned_at' => null])->save();
        }
        $asset->refresh();
        $key = $asset->quarantine_key;
        if ($key === null || $asset->scan_status !== 'pending') {
            return;
        }
        $quarantine = $this->storage->disk(EvidenceUploadService::QUARANTINE_DISK);
        $tempKey = 'evidence-scan-'.Str::ulid();
        $tempDisk = $this->storage->disk('local');
        $temp = storage_path('app/private/'.$tempKey);
        try {
            $input = $quarantine->readStream($key);
            if (! is_resource($input)) {
                throw new RuntimeException('The quarantine object could not be read.');
            }
            $bytes = stream_get_contents($input);
            if (! is_string($bytes) || $tempDisk->put($tempKey, $bytes) === false) {
                throw new RuntimeException('The quarantine object could not be staged.');
            }
            fclose($input);

            $scanStream = fopen($temp, 'rb');
            if (! is_resource($scanStream)) {
                throw new RuntimeException('The evidence object could not be scanned.');
            }
            try {
                $scan = $this->clam->scan($scanStream);
            } finally {
                fclose($scanStream);
            }
            if ($scan === 'infected') {
                $this->reject($asset->id, $key, 'infected');

                return;
            }
            if ($scan !== 'clean') {
                throw new RuntimeException('The scanner did not return clean or infected.');
            }
            $mime = EvidenceUploadService::detectedType($temp);
            $allowed = (array) config('evidence.types');
            $extension = strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION));
            if (! isset($allowed[$mime]) || ! in_array($extension, $allowed[$mime], true)) {
                $this->reject($asset->id, $key, 'type_mismatch');

                return;
            }
            $sha = hash_file('sha256', $temp);
            if (! is_string($sha)) {
                throw new RuntimeException('Could not hash the quarantine object.');
            }
            $blobKey = 'sha256/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
            $disk = $this->storage->disk(self::EVIDENCE_DISK);
            $stream = fopen($temp, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('The evidence object could not be opened.');
            }
            $disk->writeStream($blobKey, $stream);
            fclose($stream);
            $now = CarbonImmutable::now('UTC');
            $retention = $now->addDays((int) config('evidence.blob_retention_days'))->toDateString();
            $this->applyObjectLock($disk, $temp, $blobKey, $mime, $now->addDays((int) config('evidence.blob_retention_days')));
            $this->db->transaction(function () use ($assetId, $sha, $blobKey, $mime, $temp, $now, $retention): void {
                $db = $this->db->connection();
                $db->statement('INSERT INTO evidence_blobs (sha256, storage_disk, storage_key, size_bytes, detected_mime, first_scan_engine, first_scanned_at, retention_until, legal_hold, integrity_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [
                    $sha, self::EVIDENCE_DISK, $blobKey, filesize($temp), $mime, config('evidence.scan.engine'), $now, $retention, false, 'ok', $now,
                ]);
                $blobId = (int) $db->getPdo()->lastInsertId();
                if ($blobId < 1) {
                    $blobId = (int) $db->table('evidence_blobs')->where('sha256', $sha)->value('id');
                }
                $locked = EvidenceAsset::query()->lockForUpdate()->find($assetId);
                if ($locked === null || in_array($locked->status, ['available', 'rejected', 'withdrawn'], true)) {
                    return;
                }
                $locked->forceFill(['evidence_blob_id' => $blobId, 'status' => 'available', 'scan_status' => 'clean', 'scan_engine' => config('evidence.scan.engine'), 'scanned_at' => $now, 'quarantine_key' => null])->save();
                EvidenceCustodyEvent::query()->insert(['evidence_asset_id' => $assetId, 'event' => 'scanned', 'actor_type' => 'system', 'actor_id' => null, 'occurred_at' => $now]);
                $this->audit->record('evidence.scanned', $locked, ['result' => 'clean', 'sha256' => $sha], Actor::system());
            });
            $this->deleteQuarantine($quarantine, $key);
        } catch (Throwable $e) {
            $this->markError($asset->id, $e);
            throw $e;
        } finally {
            $tempDisk->delete($tempKey);
        }
    }

    private function reject(int $assetId, string $key, string $reason): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->db->transaction(function () use ($assetId, $reason, $now): void {
            $asset = EvidenceAsset::query()->lockForUpdate()->find($assetId);
            if ($asset === null || in_array($asset->status, ['available', 'rejected', 'withdrawn'], true)) {
                return;
            }
            $asset->forceFill(['status' => 'rejected', 'scan_status' => $reason === 'infected' ? 'infected' : 'clean', 'scan_engine' => config('evidence.scan.engine'), 'scanned_at' => $now, 'quarantine_key' => null])->save();
            EvidenceCustodyEvent::query()->insert(['evidence_asset_id' => $assetId, 'event' => 'scanned', 'actor_type' => 'system', 'actor_id' => null, 'occurred_at' => $now]);
            EvidenceCustodyEvent::query()->insert(['evidence_asset_id' => $assetId, 'event' => 'rejected', 'actor_type' => 'system', 'actor_id' => null, 'occurred_at' => $now]);
            $this->audit->record('evidence.rejected', $asset, ['reason' => $reason], Actor::system());
        });
        $this->deleteQuarantine($this->storage->disk(EvidenceUploadService::QUARANTINE_DISK), $key);
    }

    private function markError(int $assetId, Throwable $error): void
    {
        $this->db->transaction(function () use ($assetId): void {
            $asset = EvidenceAsset::query()->lockForUpdate()->find($assetId);
            if ($asset !== null && $asset->status === 'quarantined' && $asset->scan_status === 'pending') {
                $asset->forceFill(['scan_status' => 'error', 'scanned_at' => CarbonImmutable::now('UTC')])->save();
            }
        });
        Log::warning('evidence.scan_error', ['asset_id' => $assetId, 'error' => $error::class]);
    }

    private function deleteQuarantine(Filesystem $disk, string $key): void
    {
        try {
            if ($disk->delete($key) === false) {
                throw new RuntimeException('The quarantine object was not deleted.');
            }
        } catch (Throwable $e) {
            Log::warning('evidence.quarantine_orphan', ['quarantine_key' => $key, 'cleanup_error' => $e::class]);
        }
    }

    private function applyObjectLock(Filesystem $disk, string $path, string $key, string $mime, CarbonImmutable $until): void
    {
        if (! method_exists($disk, 'getClient')) {
            return;
        }
        $client = $disk->getClient();
        $client->putObject([
            'Bucket' => (string) config('filesystems.disks.evidence.bucket'),
            'Key' => $key,
            'Body' => fopen($path, 'rb'),
            'ContentType' => $mime,
            'ObjectLockMode' => 'GOVERNANCE',
            'ObjectLockRetainUntilDate' => $until->toIso8601String(),
        ]);
    }
}
