<?php

namespace Modules\Platform\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use RuntimeException;

/**
 * Writes an object **once**, with its object-lock retention on that same write (ADR-0007: governance mode on the
 * locked buckets). Flysystem cannot pass lock options, and a plain write followed by a second, locked write would leave
 * an earlier version without retention, so on the S3 store the SDK's upload is used directly (no ACL, as the
 * `s3-private` driver; single or multipart). The version ID is returned, for retention and legal-hold changes later
 * (P1-35), so a write that returns none fails closed. Any other disk is written plainly and returns no version, in
 * tests only (the fakes): anywhere else it would store evidence without retention. `legalHold()` sets or clears the
 * legal hold on a stored version (P1-35).
 */
final class LockedObjects
{
    /** @param  resource  $stream */
    public static function write(Filesystem $disk, string $key, $stream, string $contentType, CarbonImmutable $retainUntil): ?string
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Locked objects need the S3 object store.');
            }
            $disk->writeStream($key, $stream);

            return null;
        }

        $config = $disk->getConfig();
        $result = $disk->getClient()->upload((string) $config['bucket'], ltrim(((string) ($config['root'] ?? '')).'/'.$key, '/'), $stream, '', [
            'params' => [
                'ContentType' => $contentType,
                'ObjectLockMode' => 'GOVERNANCE',
                'ObjectLockRetainUntilDate' => $retainUntil->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);

        $version = $result['VersionId'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new RuntimeException('The object store returned no version: is versioning on for this bucket?');
        }

        return $version;
    }

    /**
     * Sets or clears the S3 legal hold on one stored version (P1-35, ADR-0007): while on, the version cannot be deleted
     * whatever its retention date. The version is required, so the hold never lands on a later version instead. Any
     * other disk is skipped, in tests only.
     */
    public static function legalHold(Filesystem $disk, string $key, ?string $versionId, bool $on): void
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Legal holds need the S3 object store.');
            }

            return;
        }
        if ($versionId === null || $versionId === '') {
            throw new RuntimeException('A legal hold needs the stored version of the object.');
        }

        $config = $disk->getConfig();
        $disk->getClient()->putObjectLegalHold([
            'Bucket' => (string) $config['bucket'],
            'Key' => ltrim(((string) ($config['root'] ?? '')).'/'.$key, '/'),
            'VersionId' => $versionId,
            'LegalHold' => ['Status' => $on ? 'ON' : 'OFF'],
        ]);
    }
}
