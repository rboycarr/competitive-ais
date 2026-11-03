<?php

namespace Modules\Platform\Storage;

use League\Flysystem\AwsS3V3\VisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Visibility for private buckets on the S3-compatible store (ADR-0007, P1-32a): every object is private and **no ACL
 * is ever sent**. Flysystem's S3 adapter otherwise adds `ACL: private` to every write, which the store refuses for the
 * application's key: it holds no ACL permission, and must not (an ACL could make an object public). The AWS SDK omits
 * an empty ACL, on single and multipart uploads alike (checked against SeaweedFS 4.51).
 */
final class PrivateBucketVisibility implements VisibilityConverter
{
    public function visibilityToAcl(string $visibility): string
    {
        return '';
    }

    /** @param  array<int, mixed>  $grants */
    public function aclToVisibility(array $grants): string
    {
        return Visibility::PRIVATE;
    }

    public function defaultForDirectories(): string
    {
        return Visibility::PRIVATE;
    }
}
