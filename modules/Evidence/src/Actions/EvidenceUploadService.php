<?php

namespace Modules\Evidence\Actions;

use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Platform\Support\WebAddress;
use Normalizer;
use Throwable;

/**
 * Uploading evidence into quarantine (P1-32a, docs/03 Evidence, ADR-0007, docs/07 T7: uploads are untrusted):
 * - needs evidence.upload; nobody classifies an asset above what they can see;
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

    private const MAX_TEXT = 10000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly AccessScope $access,
        private readonly Gate $gate,
        private readonly Filesystems $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, description?, source_url?, captured_at (Y-m-d H:i or ISO 8601, UTC), classification
     */
    public function upload(Authenticatable $actor, UploadedFile $file, array $data): EvidenceAsset
    {
        if (! $this->gate->forUser($actor)->allows('evidence.upload')) {
            throw new AuthorizationException('You are not allowed to upload evidence.');
        }
        $asset = new EvidenceAsset;
        $asset->forceFill($this->attributes($file, $data) + [
            'ulid' => (string) Str::ulid(),
            'status' => 'quarantined',
            'scan_status' => 'pending',
            'uploaded_by' => (int) $actor->getAuthIdentifier(),
        ]);
        if (! $this->access->allows($actor, $asset)) {
            throw ValidationException::withMessages(['classification' => 'You cannot classify evidence above what you can see.']);
        }
        $asset->quarantine_key = 'quarantine/'.$asset->ulid;

        $disk = $this->storage->disk(self::QUARANTINE_DISK);
        $stream = fopen($file->getRealPath(), 'rb') ?: throw ValidationException::withMessages(['file' => 'The upload could not be read; try again.']);
        try {
            $disk->writeStream($asset->quarantine_key, $stream);
        } finally {
            fclose($stream);
        }

        try {
            return $this->db->transaction(function () use ($actor, $asset): EvidenceAsset {
                $asset->save();
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
                $disk->delete($asset->quarantine_key);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }

            throw $e;
        }
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
            'title' => preg_replace('/\s+/u', ' ', $title),
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
