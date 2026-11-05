<?php

namespace Modules\Evidence\Tests\Feature;

use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToDeleteFile;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-38 / TASK-0064 #2: evidence:sweep-quarantine removes quarantine objects that no asset waits for, once they are
 * older than `evidence.quarantine_orphan_minutes`, logged by key only; recent objects, objects an asset waits for, and
 * keys of any other form are left. The quarantine store is the real S3 adapter with the SDK's mock handler.
 */
class EvidenceQuarantineSweepTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @var array<string, CarbonImmutable> key => last modified */
    private array $objects = [];

    /** @var list<string> */
    private array $deleted = [];

    private ?string $refuses = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.evidence-quarantine.key' => 'synthetic-key', 'filesystems.disks.evidence-quarantine.secret' => 'synthetic-secret']);
        $disk = Storage::disk(EvidenceUploadService::QUARANTINE_DISK);
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler(array_fill(0, 20, fn (CommandInterface $command): Result => new Result($this->answer($command)))));
    }

    public function test_only_old_objects_without_a_waiting_asset_are_removed_and_logged_by_key(): void
    {
        $old = CarbonImmutable::now('UTC')->subMinutes(61);
        $orphan = 'quarantine/'.Str::ulid();
        $refused = 'quarantine/'.Str::ulid();
        $recent = 'quarantine/'.Str::ulid();
        $waiting = $this->quarantinedAsset()->quarantine_key;
        $this->objects = [$orphan => $old, $refused => $old, $recent => CarbonImmutable::now('UTC')->subMinutes(5), (string) $waiting => $old, 'quarantine/notes.txt' => $old];
        $this->refuses = $refused;
        $log = Log::spy();

        $this->assertSame(1, Artisan::call('evidence:sweep-quarantine'));

        $this->assertStringContainsString('removed: 1; failed: 1; unexpected keys left: 1.', Artisan::output());
        $this->assertEqualsCanonicalizing([$orphan, $refused], $this->deleted, 'only old orphans were deleted, the refused one attempted');
        $log->shouldHaveReceived('info')->once()->with('evidence.quarantine_orphan_removed', ['quarantine_key' => $orphan]);
        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'evidence.quarantine_orphan'
            && $context['quarantine_key'] === $refused && $context['cleanup_error'] === UnableToDeleteFile::class);
    }

    public function test_a_clean_sweep_succeeds(): void
    {
        $orphan = 'quarantine/'.Str::ulid();
        $this->objects = [$orphan => CarbonImmutable::now('UTC')->subHours(3)];

        $this->assertSame(0, Artisan::call('evidence:sweep-quarantine'));
        $this->assertSame([$orphan], $this->deleted);
        $this->assertStringContainsString('removed: 1; failed: 0; unexpected keys left: 0.', Artisan::output());
    }

    /** @return array<string, mixed> the result data */
    private function answer(CommandInterface $command): array
    {
        if ($command->getName() === 'ListObjectsV2') {
            $contents = [];
            foreach ($this->objects as $key => $modified) {
                $contents[] = ['Key' => $key, 'LastModified' => new DateTimeResult($modified->format('Y-m-d\TH:i:s\Z')), 'Size' => 10];
            }

            return ['Contents' => $contents, 'KeyCount' => count($contents), 'IsTruncated' => false];
        }
        if ($command->getName() === 'DeleteObject') {
            $this->deleted[] = (string) $command['Key'];
            if ($command['Key'] === $this->refuses) {
                throw new AwsException('Synthetic delete failure.', $command, ['code' => 'AccessDenied']);
            }

            return [];
        }

        throw new RuntimeException('Unexpected store request '.$command->getName().'.');
    }

    private function quarantinedAsset(): EvidenceAsset
    {
        $uploader = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Internal);
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid(),
            'original_filename' => 'a.pdf', 'size_bytes' => 10, 'title' => 'Synthetic waiting', 'captured_at' => now(), 'uploaded_by' => $uploader->id,
            'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset;
    }
}
