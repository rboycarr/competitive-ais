<?php

namespace Modules\Platform\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Short-lived, attachment-only download links for objects in the private buckets (P1-34, ADR-0007, docs/07 T7):
 * the store serves the exact stored version, as `application/octet-stream` with `Content-Disposition: attachment`,
 * so a browser never renders it. The application talks to the store on its internal address; a browser cannot,
 * and a pre-signed URL signs its host, so the link is signed for the disk's `download_endpoint` when one is set
 * (the address browsers reach the same store on). Outside local and tests the link must be https. Any other disk
 * (the test fakes) builds its link through `temporaryUrl`, in tests only.
 */
final class SignedDownloads
{
    public static function url(Filesystem $disk, string $key, ?string $versionId, string $filename, DateTimeInterface $expires): string
    {
        $options = array_filter([
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::name($filename), self::fallback($filename)),
            'VersionId' => $versionId,
        ], fn (?string $value): bool => $value !== null);

        if (! $disk instanceof AwsS3V3Adapter) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('Download links need the S3 object store.');
            }

            return $disk->temporaryUrl($key, $expires, $options);
        }

        $config = $disk->getConfig();
        $endpoint = (string) ($config['download_endpoint'] ?? '');
        $signer = $endpoint === '' ? $disk : Storage::build(['endpoint' => $endpoint, 'download_endpoint' => null] + $config);
        $url = $signer->temporaryUrl($key, $expires, $options);
        if (! str_starts_with($url, 'https://') && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('Download links must use https: set the disk\'s download endpoint.');
        }

        return $url;
    }

    /** The cleaned display name, without the characters a disposition header cannot carry. */
    private static function name(string $filename): string
    {
        $name = trim(str_replace(['/', '\\', '"'], '_', $filename));

        return $name === '' ? 'evidence' : $name;
    }

    /** An ASCII stand-in for clients that ignore `filename*`. */
    private static function fallback(string $filename): string
    {
        return (string) preg_replace('/[^\x20-\x7E]|%/u', '_', self::name($filename));
    }
}
