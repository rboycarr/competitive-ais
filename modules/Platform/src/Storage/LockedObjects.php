<?php

namespace Modules\Platform\Storage;

use Aws\Exception\AwsException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
 * legal hold on a stored version (P1-35); `extendRetention()` and `state()` extend and read its lock (P1-38).
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

        $disk->getClient()->putObjectLegalHold(self::version($disk, $key, $versionId) + ['LegalHold' => ['Status' => $on ? 'ON' : 'OFF']]);
    }

    /**
     * Extends the governance retention of one stored version to $retainUntil (P1-38). Governance retention can be
     * shortened only with BypassGovernanceRetention, which the application never holds or sends, so a date earlier
     * than the version's own is refused by the store. Any other disk is skipped, in tests only.
     */
    public static function extendRetention(Filesystem $disk, string $key, ?string $versionId, CarbonImmutable $retainUntil): void
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Retention needs the S3 object store.');
            }

            return;
        }

        $disk->getClient()->putObjectRetention(self::version($disk, $key, $versionId) + [
            'Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => $retainUntil->utc()->format('Y-m-d\TH:i:s\Z')],
        ]);
    }

    /**
     * The lock state of one stored version, as the store holds it (P1-38): its legal hold and its retention date (null
     * when it has none). A version without either answers that it has no such lock (unlessUnlocked), read as "off"
     * and "none"; any other error is raised, so a missing version is never mistaken for an unlocked one. Any other disk returns
     * null ("unknown"), in tests only.
     *
     * @return array{legalHold: bool, retainUntil: ?CarbonImmutable}|null
     *
     * @phpstan-impure
     */
    public static function state(Filesystem $disk, string $key, ?string $versionId): ?array
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Object lock state needs the S3 object store.');
            }

            return null;
        }

        $client = $disk->getClient();
        $params = self::version($disk, $key, $versionId);
        $hold = self::unlessUnlocked(fn (): mixed => $client->getObjectLegalHold($params)['LegalHold']['Status'] ?? null);
        $until = self::unlessUnlocked(fn (): mixed => $client->getObjectRetention($params)['Retention']['RetainUntilDate'] ?? null);

        return [
            'legalHold' => $hold === 'ON',
            'retainUntil' => match (true) {
                $until instanceof DateTimeInterface => CarbonImmutable::instance($until)->utc(),
                is_string($until) && $until !== '' => CarbonImmutable::parse($until)->utc(),
                default => null,
            },
        ];
    }

    /**
     * The bytes of one stored version, as a stream the caller closes (P1-48: the integrity re-hash), or null when the
     * store has no such object or version. Any other error is raised, so an unreachable store is never taken for a
     * missing object. Any other disk reads the key, in tests only: the fakes keep no versions (TASK-0094 #3), so
     * version fidelity is tested against the S3 adapter with the SDK's mock handler.
     *
     * @return resource|null
     */
    public static function readVersion(Filesystem $disk, string $key, ?string $versionId)
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Stored versions are read from the S3 object store.');
            }

            return $disk->exists($key) ? $disk->readStream($key) : null;
        }

        try {
            $result = $disk->getClient()->getObject(self::version($disk, $key, $versionId));
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NoSuchVersion'], true)) {
                return null;
            }

            throw $e;
        }

        return $result['Body']->detach();
    }

    /** @return array{Bucket: string, Key: string, VersionId: string} */
    private static function version(AwsS3V3Adapter $disk, string $key, ?string $versionId): array
    {
        if ($versionId === null || $versionId === '') {
            throw new RuntimeException('Object lock changes need the stored version of the object.');
        }
        $config = $disk->getConfig();

        return ['Bucket' => (string) $config['bucket'], 'Key' => ltrim(((string) ($config['root'] ?? '')).'/'.$key, '/'), 'VersionId' => $versionId];
    }

    /**
     * The answer of a lock read, or null when the version has no lock configuration of that kind: S3's
     * NoSuchObjectLockConfiguration, or SeaweedFS's own codes for a version never held or never retained (found
     * against the local store, P1-38).
     */
    private static function unlessUnlocked(callable $read): mixed
    {
        try {
            return $read();
        } catch (AwsException $e) {
            if (in_array($e->getAwsErrorCode(), ['NoSuchObjectLockConfiguration', 'NoSuchObjectLegalHold', 'NoSuchObjectRetention'], true)) {
                return null;
            }

            throw $e;
        }
    }
}
