<?php

namespace Modules\Platform\Tests\Feature;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Visibility;
use Modules\Platform\Storage\PrivateBucketVisibility;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * P1-32a / ADR-0007: the `s3-private` driver writes to the private buckets without any ACL, because the application's
 * key holds no ACL permission (SeaweedFS refuses `ACL: private` from it). Requests are captured by the AWS SDK's mock
 * handler; nothing reaches a store.
 */
class PrivateObjectStorageTest extends TestCase
{
    public function test_writes_to_the_quarantine_disk_send_no_acl(): void
    {
        config(['filesystems.disks.evidence-quarantine.key' => 'synthetic-key', 'filesystems.disks.evidence-quarantine.secret' => 'synthetic-secret']);
        $disk = Storage::disk('evidence-quarantine');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);

        $client = $disk->getClient();
        $requests = [];
        $client->getHandlerList()->setHandler(new MockHandler([
            function (CommandInterface $command, RequestInterface $request) use (&$requests): Result {
                $requests[] = [$command->getName(), $command['ACL'] ?? null, $request->getHeaderLine('x-amz-acl'), $request->getUri()->getPath()];

                return new Result([]);
            },
        ]));

        $disk->put('quarantine/probe', 'Synthetic.');
        $this->assertSame([['PutObject', '', '', '/evidence-quarantine/quarantine/probe']], $requests);
    }

    public function test_every_object_is_private(): void
    {
        $visibility = new PrivateBucketVisibility;
        $this->assertSame(['', '', Visibility::PRIVATE, Visibility::PRIVATE], [
            $visibility->visibilityToAcl(Visibility::PUBLIC), $visibility->visibilityToAcl(Visibility::PRIVATE),
            $visibility->aclToVisibility([['Permission' => 'READ', 'Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers']]]),
            $visibility->defaultForDirectories(),
        ]);
    }
}
