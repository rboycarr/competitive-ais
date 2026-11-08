<?php

namespace Modules\Platform\Tests\Feature;

use ArrayObject;
use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Visibility;
use Modules\Platform\Storage\LockedObjects;
use Modules\Platform\Storage\PrivateBucketVisibility;
use Modules\Platform\Storage\SignedDownloads;
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

    /**
     * P1-34: a download link is signed for the address browsers use (the internal endpoint is unreachable for them),
     * for the stored version, attachment-only as octet-stream, and expires when asked. Signing is local: no request.
     */
    public function test_download_links_are_signed_for_the_browser_endpoint(): void
    {
        config([
            'filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret',
            'filesystems.disks.evidence.endpoint' => 'http://seaweedfs:8333', 'filesystems.disks.evidence.use_path_style_endpoint' => true,
            'filesystems.disks.evidence.download_endpoint' => 'https://files.example.test',
        ]);
        $this->app['env'] = 'production';
        try {
            $url = SignedDownloads::url(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', 'synthetic-version-1', 'report.pdf', CarbonImmutable::now('UTC')->addSeconds(60));
        } finally {
            $this->app['env'] = 'testing';
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $this->assertSame(['https', 'files.example.test', '/evidence/sha256/ab/cd/abcdef'], [$parts['scheme'] ?? null, $parts['host'] ?? null, $parts['path'] ?? null]);
        // The SDK counts the lifetime from its own clock, so a second may pass between the two (CI #231): 59 or 60.
        $this->assertContains($query['X-Amz-Expires'] ?? null, ['59', '60']);
        $this->assertSame(['synthetic-version-1', 'application/octet-stream', 'attachment; filename=report.pdf'], [
            $query['versionId'] ?? null, $query['response-content-type'] ?? null, $query['response-content-disposition'] ?? null,
        ]);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
    }

    /** Outside local and tests, a link that would travel over plain http is refused. */
    public function test_download_links_must_use_https_outside_local(): void
    {
        config([
            'filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret',
            'filesystems.disks.evidence.endpoint' => 'http://seaweedfs:8333', 'filesystems.disks.evidence.use_path_style_endpoint' => true,
            'filesystems.disks.evidence.download_endpoint' => 'http://files.example.test',
        ]);
        $this->app['env'] = 'production';
        try {
            SignedDownloads::url(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', null, 'report.pdf', CarbonImmutable::now('UTC')->addSeconds(60));
            $this->fail('A plain-http download link was issued outside local.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /** P1-35: a legal hold needs the stored version (never "the latest"), and the S3 store outside tests. */
    public function test_legal_holds_need_the_version_and_the_s3_store(): void
    {
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $disk = Storage::disk('evidence');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $sent = 0;
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler([function () use (&$sent): Result {
            $sent++;

            return new Result([]);
        }]));
        $this->assertThrows(fn () => LockedObjects::legalHold($disk, 'sha256/ab/cd/abcdef', null, true), RuntimeException::class, 'stored version');
        $this->assertSame(0, $sent);

        Storage::fake('evidence');
        LockedObjects::legalHold(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', null, true); // the fakes, in tests: skipped
        $this->app['env'] = 'production';
        try {
            $this->assertThrows(fn () => LockedObjects::legalHold(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', 'v1', true), RuntimeException::class, 'S3 object store');
        } finally {
            $this->app['env'] = 'testing';
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

    /**
     * P1-38: retention is extended in governance mode on the stored version, never with a bypass; the lock state is
     * read from the version, a version never held or retained reads as "off" and "none" (S3's and SeaweedFS's codes,
     * as the local store answered), and any other error, such as a missing version, is raised.
     */
    public function test_locked_objects_extend_retention_and_read_the_lock_state(): void
    {
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $disk = Storage::disk('evidence');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $sent = new ArrayObject;
        // In order: the extension, then three state reads (no lock; held and retained; a missing version).
        $answers = [
            [], ['code' => 'NoSuchObjectLegalHold'], ['code' => 'NoSuchObjectLockConfiguration'],
            ['LegalHold' => ['Status' => 'ON']], ['Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => new DateTimeResult('2027-01-01T00:00:00Z')]],
            ['code' => 'NoSuchKey'],
        ];
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler(array_map(fn (array $answer): callable => function (CommandInterface $command) use ($answer, $sent): Result {
            $sent->append([$command->getName(), $command['VersionId'] ?? null, $command['Retention'] ?? null, $command['BypassGovernanceRetention'] ?? null]);
            if (isset($answer['code'])) {
                throw new AwsException('Synthetic answer.', $command, ['code' => $answer['code']]);
            }

            return new Result($answer);
        }, $answers)));

        LockedObjects::extendRetention($disk, 'sha256/ab/cd/abcdef', 'v1', CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'));
        $this->assertSame([['PutObjectRetention', 'v1', ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => '2027-01-01T00:00:00Z'], null]], $sent->getArrayCopy());

        $this->assertSame(['legalHold' => false, 'retainUntil' => null], LockedObjects::state($disk, 'sha256/ab/cd/abcdef', 'v1'));
        $held = LockedObjects::state($disk, 'sha256/ab/cd/abcdef', 'v1');
        $this->assertNotNull($held);
        $this->assertSame([true, '2027-01-01T00:00:00Z'], [$held['legalHold'], $held['retainUntil']?->format('Y-m-d\TH:i:s\Z')]);
        $this->assertThrows(fn () => LockedObjects::state($disk, 'sha256/ab/cd/abcdef', 'v1'), AwsException::class);
        $this->assertSame(['GetObjectLegalHold', 'GetObjectRetention', 'GetObjectLegalHold', 'GetObjectRetention', 'GetObjectLegalHold'], array_column(array_slice($sent->getArrayCopy(), 1), 0));
        $this->assertThrows(fn () => LockedObjects::extendRetention($disk, 'sha256/ab/cd/abcdef', null, CarbonImmutable::now('UTC')), RuntimeException::class, 'stored version');

        Storage::fake('evidence'); // the fakes, in tests: skipped, or unknown
        LockedObjects::extendRetention(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', null, CarbonImmutable::now('UTC'));
        $this->assertNull(LockedObjects::state(Storage::disk('evidence'), 'sha256/ab/cd/abcdef', null));
    }
}
