<?php

namespace Modules\Platform\Tests\Feature;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Visibility;
use Modules\Platform\Storage\LockedObjects;
use Modules\Platform\Storage\PrivateBucketVisibility;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
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

    /**
     * P1-32b: a blob is written once, with its governance-mode retention on that same write (ADR-0007), and no ACL
     * (as every write on this driver). The version ID the store returns is handed back for later retention changes
     * (P1-35).
     */
    public function test_locked_objects_writes_once_with_retention_and_no_acl(): void
    {
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $disk = Storage::disk('evidence');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);

        $client = $disk->getClient();
        $requests = [];
        $client->getHandlerList()->setHandler(new MockHandler([
            function (CommandInterface $command, RequestInterface $request) use (&$requests): Result {
                $requests[] = [
                    $command->getName(), $command['ACL'] ?? null, $request->getHeaderLine('x-amz-acl'),
                    $command['ObjectLockMode'] ?? null, $command['ObjectLockRetainUntilDate'] ?? null, $command['ContentType'] ?? null,
                    $request->getUri()->getPath(),
                ];

                return new Result(['VersionId' => 'synthetic-version-1']);
            },
        ]));

        $stream = fopen('php://memory', 'r+') ?: $this->fail('Could not open a memory stream.');
        fwrite($stream, 'Synthetic blob bytes.');
        rewind($stream);
        $until = CarbonImmutable::parse('2026-12-04T00:00:00Z');
        $version = LockedObjects::write($disk, 'sha256/ab/cd/abcdef', $stream, 'application/pdf', $until);
        fclose($stream);

        $this->assertSame('synthetic-version-1', $version);
        $this->assertSame(
            [['PutObject', '', '', 'GOVERNANCE', '2026-12-04T00:00:00Z', 'application/pdf', '/evidence/sha256/ab/cd/abcdef']],
            $requests,
        );
    }

    /** A disk that is not the S3 adapter (the test fakes) is written plainly, with no version returned. */
    public function test_locked_objects_falls_back_to_a_plain_write_on_other_disks(): void
    {
        Storage::fake('evidence');
        $stream = fopen('php://memory', 'r+') ?: $this->fail('Could not open a memory stream.');
        fwrite($stream, 'Synthetic blob bytes.');
        rewind($stream);

        $version = LockedObjects::write(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', $stream, 'application/pdf', CarbonImmutable::now('UTC')->addDays(30));
        fclose($stream);

        $this->assertNull($version);
        Storage::disk('evidence')->assertExists('sha256/ab/cd/abcdef');
        $this->assertSame('Synthetic blob bytes.', Storage::disk('evidence')->get('sha256/ab/cd/abcdef'));
    }

    /** ADR-0007: retention and legal hold act on the stored version, so a write without one fails closed. */
    public function test_locked_objects_fail_closed_without_a_version(): void
    {
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $disk = Storage::disk('evidence');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler([new Result([])]));

        $stream = fopen('php://memory', 'r+') ?: $this->fail('Could not open a memory stream.');
        fwrite($stream, 'Synthetic blob bytes.');
        rewind($stream);
        try {
            LockedObjects::write($disk, 'sha256/ab/cd/abcdef', $stream, 'application/pdf', CarbonImmutable::now('UTC')->addDays(30));
            $this->fail('A write without a version was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no version', $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    /** Outside tests, a disk without object lock would store evidence without retention: refused. */
    public function test_locked_objects_refuse_a_plain_disk_outside_tests(): void
    {
        Storage::fake('evidence');
        $this->app['env'] = 'production';
        $stream = fopen('php://memory', 'r+') ?: $this->fail('Could not open a memory stream.');
        try {
            LockedObjects::write(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', $stream, 'application/pdf', CarbonImmutable::now('UTC')->addDays(30));
            $this->fail('A plain disk was accepted outside tests.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('S3 object store', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
            fclose($stream);
        }
        Storage::disk('evidence')->assertMissing('sha256/ab/cd/abcdef');
    }
}
