<?php

namespace Modules\Platform\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Writes an object **once**, with its object-lock retention on that same write (ADR-0007: governance mode on the
 * locked buckets). Flysystem cannot pass lock options, and a plain write followed by a second, locked write would leave
 * an earlier version without retention, so on the S3 store the SDK's upload is used directly (no ACL, as the
 * `s3-private` driver; single or multipart). The version ID is returned, for retention and legal-hold changes later
 * (P1-35). Any other disk (the test fakes) is written plainly and returns no version.
 */
final class LockedObjects
{
    /** @param  resource  $stream */
    public static function write(Filesystem $disk, string $key, $stream, string $contentType, CarbonImmutable $retainUntil): ?string
    {
        if (! $disk instanceof AwsS3V3Adapter) {
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

        return is_string($version) && $version !== '' ? $version : null;
    }
}
