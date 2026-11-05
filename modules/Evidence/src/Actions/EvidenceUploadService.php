<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceAssetCompetitor;
use Modules\Evidence\Models\EvidenceAssetMarket;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Platform\Support\WebAddress;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\MarketLookup;
use Normalizer;
use RuntimeException;
use Throwable;

/**
 * Uploading evidence into quarantine (P1-32a, docs/03 Evidence, ADR-0007, docs/07 T7: uploads are untrusted):
 * - needs evidence.upload; nobody classifies an asset above what they can see;
 * - the competitors and markets it is about (P1-36a) are declared by ULID: competitors the uploader can see, markets
 *   that exist, at most MAX_SCOPES each; the uploader must be able to see the resulting asset (all-of), so nobody
 *   uploads evidence they could not read back. The scopes are saved with the asset, in its transaction;
 * - the file must be non-empty, at most `evidence.max_bytes`, of a type in `evidence.types` detected from its bytes,
 *   with an extension that matches that type (a renamed executable or a PDF called .png is refused before it is
 *   stored); the scan checks the stored bytes again (P1-32b);
 * - the file name is cleaned for display only (no path, no control or format characters, NFC, at most 255
 *   characters) and is never part of the storage key, which is `quarantine/<asset ULID>`;
 * - the source URL, if given, is an http(s) address without a user name or password: recorded, never fetched;
 * - the object is written first, then the asset, its `uploaded` custody event and the audit event in one
 *   transaction; if the transaction fails, the object is deleted, so an asset never exists without its object.
 *   An object whose deletion also fails is left in the quarantine bucket without an asset (reported).
 */
class EvidenceUploadService
{
    public const QUARANTINE_DISK = 'evidence-quarantine';

    public const MAX_SCOPES = 20;

    private const MAX_TEXT = 10000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly Filesystems $storage,
        private readonly CompetitorLookup $competitors,
        private readonly MarketLookup $markets,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, description?, source_url?, captured_at (Y-m-d H:i or ISO 8601, UTC), classification
     */
    public function upload(Authenticatable $actor, UploadedFile $file, array $data): EvidenceAsset
    {
        if (! $this->gate->forUser($actor)->allows('evidence.upload')) {
            throw new AuthorizationException('You are not allowed to upload evidence.');
        }
        $userId = (int) $actor->getAuthIdentifier();
        $asset = new EvidenceAsset;
        $asset->forceFill($this->attributes($file, $data) + [
            'ulid' => (string) Str::ulid(),
            'status' => 'quarantined',
            'scan_status' => 'pending',
            'uploaded_by' => $userId,
        ]);
        [$competitorIds, $marketIds] = $this->scopes($actor, $data);
        $asset->setRelation('competitorScopes', new Collection)->setRelation('marketScopes', new Collection);
        if (! $this->access->allows($actor, $asset)) {
            throw ValidationException::withMessages(['classification' => 'You cannot classify evidence above what you can see.']);
        }
        // P1-36a: declared scopes, checked as the asset will be seen (all-of): the uploader must see the result.
        $asset->setRelation('competitorScopes', new Collection(array_map(fn (int $id): EvidenceAssetCompetitor => (new EvidenceAssetCompetitor)->forceFill(['competitor_id' => $id]), $competitorIds)));
        $asset->setRelation('marketScopes', new Collection(array_map(fn (int $id): EvidenceAssetMarket => (new EvidenceAssetMarket)->forceFill(['market_id' => $id]), $marketIds)));
        if (! $this->access->allows($actor, $asset)) {
            throw ValidationException::withMessages(['scopes' => 'At this classification, you need access to every competitor and market you choose.']);
        }
        $key = $asset->quarantine_key = 'quarantine/'.$asset->ulid;
        $disk = $this->storage->disk(self::QUARANTINE_DISK);

        // One cleanup boundary for the write and the records (TASK-0064 #1): a write that fails part-way (e.g. during a
        // multipart upload) leaves nothing either. Deleting a key that was never written is harmless.
        try {
            $stream = fopen($file->getRealPath(), 'rb') ?: throw ValidationException::withMessages(['file' => 'The upload could not be read; try again.']);
            try {
                $disk->writeStream($key, $stream);
            } finally {
                fclose($stream);
            }

            $saved = $this->db->transaction(function () use ($actor, $asset, $userId): EvidenceAsset {
                $asset->save();
                $now = CarbonImmutable::now('UTC');
                foreach ([...$asset->competitorScopes, ...$asset->marketScopes] as $scope) {
                    $scope->forceFill(['evidence_asset_id' => $asset->id, 'origin' => 'declared', 'added_by' => $userId, 'created_at' => $now])->save();
                }
                $event = new EvidenceCustodyEvent;
                $event->forceFill([
                    'evidence_asset_id' => $asset->id,
                    'event' => 'uploaded',
                    'actor_type' => 'user',
                    'actor_id' => (int) $actor->getAuthIdentifier(),
                    'occurred_at' => CarbonImmutable::now('UTC'),
                ])->save();
                $this->audit->record('evidence.uploaded', $asset, ['classification' => $asset->classification->value], Actor::user((int) $actor->getAuthIdentifier()));

                return $asset;
            });
        } catch (Throwable $e) {
            try {
                // A disk may report a failed delete by returning false instead of throwing (TASK-0065).
                if ($disk->delete($key) === false) {
                    throw new RuntimeException('The quarantine object was not deleted.');
                }
            } catch (Throwable $cleanup) {
                // An object without an asset: logged with its generated key only, for the quarantine sweep
                // (P1-38, TASK-0064 #2). No file name, title or credentials.
                Log::warning('evidence.quarantine_orphan', ['quarantine_key' => $key, 'cleanup_error' => $cleanup::class]);
                report($cleanup);
            }

            throw $e;
        }

        // Outside the cleanup boundary (P1-32b): the asset is already committed here, so a dispatch failure must
        // never delete its quarantine object. The scheduled evidence:dispatch-scans queues the scan again once the
        // asset has waited `evidence.scan_stale_minutes` (TASK-0070 #1).
        try {
            ScanEvidenceJob::dispatch($saved->id)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }

        return $saved;
    }

    /**
     * The competitors and markets the uploader declares, by ULID (P1-36a): each competitor one the uploader can see,
     * each market one that exists; at most MAX_SCOPES of each, duplicates ignored.
     *
     * @param  array<string, mixed>  $data
     * @return array{list<int>, list<int>}
     */
    private function scopes(Authenticatable $actor, array $data): array
    {
        $ids = [];
        foreach (['competitors', 'markets'] as $field) {
            $ulids = $data[$field] ?? [];
            if (! is_array($ulids) || count($ulids) > self::MAX_SCOPES) {
                throw ValidationException::withMessages([$field => 'Choose at most '.self::MAX_SCOPES.'.']);
            }
            $ids[$field] = [];
            foreach ($ulids as $ulid) {
                // An empty or malformed entry is refused, not skipped (TASK-0075 #3): the same contract as the form.
                $found = ! is_string($ulid) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid) !== 1 ? null
                    : ($field === 'competitors' ? $this->competitors->findVisible($actor, $ulid) : $this->markets->find($ulid));
                if ($found === null) {
                    throw ValidationException::withMessages([$field => $field === 'competitors' ? 'Choose competitors you can see.' : 'Choose known markets.']);
                }
                $ids[$field][] = $found->id;
            }
        }

        return [array_values(array_unique($ids['competitors'])), array_values(array_unique($ids['markets']))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(UploadedFile $file, array $data): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload did not arrive complete; try again.']);
        }
        $size = (int) $file->getSize();
        $max = (int) config('evidence.max_bytes');
        if ($size < 1 || $size > $max) {
            throw ValidationException::withMessages(['file' => 'The file must not be empty and at most '.intdiv($max, 1024 * 1024).' MB.']);
        }
        $name = self::displayName($file->getClientOriginalName());
        /** @var array<string, list<string>> $types */
        $types = (array) config('evidence.types');
        $detected = self::detectedType((string) $file->getRealPath());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset($types[$detected]) || ! in_array($extension, $types[$detected], true)) {
            throw ValidationException::withMessages(['file' => 'This type of file is not accepted, or its content does not match its extension. Accepted: PDF, PNG, JPEG, WebP, text, CSV, DOCX, XLSX.']);
        }

        $text = function (string $field, int $max) use ($data): ?string {
            $value = $data[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw ValidationException::withMessages([$field => 'Give text.']);
            }
            // Malformed UTF-8 is refused here, before anything is stored (TASK-0064 #4).
            if ($value !== null && ! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$field => 'This text contains characters that cannot be read.']);
            }
            $value = trim((string) $value);
            if (mb_strlen($value) > $max) {
                throw ValidationException::withMessages([$field => "Use at most $max characters."]);
            }

            return $value === '' ? null : $value;
        };
        $title = $text('title', 255) ?? throw ValidationException::withMessages(['title' => 'A title is required.']);
        $sourceUrl = $text('source_url', 2048);
        if ($sourceUrl !== null && (! WebAddress::isValid($sourceUrl) || WebAddress::hasCredentials($sourceUrl))) {
            throw ValidationException::withMessages(['source_url' => 'The source URL must be an http:// or https:// address without a user name or password.']);
        }
        $classification = is_string($data['classification'] ?? null) ? Classification::tryFrom($data['classification']) : null;
        if ($classification === null) {
            throw ValidationException::withMessages(['classification' => 'Choose a known classification.']);
        }

        return [
            'original_filename' => $name,
            'size_bytes' => $size,
            'title' => (string) preg_replace('/\s+/u', ' ', $title), // valid UTF-8 (checked above), so never null
            'description' => $text('description', self::MAX_TEXT),
            'source_url' => $sourceUrl,
            'captured_at' => $this->capturedAt($data['captured_at'] ?? null),
            'classification' => $classification,
        ];
    }

    /**
     * The content type read from the file's bytes by fileinfo, never from the browser or the file name (an
     * UploadedFile's own getMimeType() may guess from the name, e.g. in tests). Empty when it cannot be read.
     */
    public static function detectedType(string $path): string
    {
        $type = $path === '' ? false : (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) ? $type : '';
    }

    /** When the evidence was captured, in UTC, not in the future (a minute of clock skew allowed). */
    private function capturedAt(mixed $value): CarbonImmutable
    {
        try {
            $at = is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
        } catch (Throwable) {
            $at = null;
        }
        if ($at === null || $at->isAfter(CarbonImmutable::now('UTC')->addMinute())) {
            throw ValidationException::withMessages(['captured_at' => 'Give when the evidence was captured; it cannot be in the future.']);
        }

        return $at;
    }

    /**
     * The client's file name, made safe to store and show: the last path segment only, NFC, without control, format
     * (e.g. right-to-left override) or separator characters, spaces collapsed, at most 255 characters keeping the
     * extension. Shown as text, never used as a storage key.
     */
    public static function displayName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        if (class_exists(Normalizer::class)) {
            $name = (string) Normalizer::normalize($name, Normalizer::FORM_C);
        }
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');
        if ($name === '') {
            return 'upload';
        }
        if (mb_strlen($name) > 255) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $suffix = $extension === '' ? '' : '.'.mb_substr($extension, 0, 16);
            $name = mb_substr($name, 0, 255 - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }
}
