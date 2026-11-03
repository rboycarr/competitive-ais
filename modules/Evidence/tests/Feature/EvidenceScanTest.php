<?php

namespace Modules\Evidence\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Scanning\ClamAvClient;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class EvidenceScanTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
    }

    public function test_clean_content_is_promoted_and_reused_by_hash(): void
    {
        $user = $this->createUser('scanner@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $first = $this->asset($user, 'one.pdf', self::PDF);
        $second = $this->asset($user, 'two.pdf', self::PDF);
        $this->clamReturns('clean');

        app(EvidenceScanService::class)->scan($first->id);
        app(EvidenceScanService::class)->scan($second->id);

        $first = $first->fresh();
        $second = $second->fresh();
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(['available', 'clean'], [$first->status, $first->scan_status]);
        $this->assertSame(['available', 'clean'], [$second->status, $second->scan_status]);
        $this->assertSame(1, EvidenceBlob::query()->count());
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->assertExists((string) EvidenceBlob::query()->sole()->storage_key);
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_type_mismatched_content_is_rejected(): void
    {
        $user = $this->createUser('reject@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $mismatch = $this->asset($user, 'spoof.png', self::PDF);
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists((string) $mismatch->quarantine_key);
        $this->clamReturns('clean');
        app(EvidenceScanService::class)->scan($mismatch->id);
        $mismatch = $mismatch->fresh();
        $this->assertNotNull($mismatch);
        $this->assertSame(['rejected', 'clean'], [$mismatch->status, $mismatch->scan_status]);

        $this->assertSame(0, EvidenceBlob::query()->count());
    }

    public function test_eicar_is_rejected(): void
    {
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        $user = $this->createUser('eicar@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $infected = $this->asset($user, 'eicar.txt', base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN30kRUlDQVItU1RBTkRBUkQtQU5USVZJUlVTLVRFU1QtRklMRSEkSCtIKg==', true));
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists((string) $infected->quarantine_key);
        $this->clamReturns('infected');
        app(EvidenceScanService::class)->scan($infected->id);
        $infected = $infected->fresh();
        $this->assertNotNull($infected);
        $this->assertSame(['rejected', 'infected'], [$infected->status, $infected->scan_status]);
    }

    public function test_scanner_errors_are_retryable_and_duplicate_jobs_are_harmless(): void
    {
        $user = $this->createUser('retry@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $asset = $this->asset($user, 'retry.pdf', self::PDF);
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('scanner unavailable'));
        }));
        try {
            app(EvidenceScanService::class)->scan($asset->id);
            $this->fail('The scanner error should be raised for the queue retry.');
        } catch (RuntimeException) {
            $fresh = $asset->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame('error', $fresh->scan_status);
        }
        $this->assertSame([30, 120, 600], (new ScanEvidenceJob($asset->id))->backoff());
        $this->assertSame((string) $asset->id, (new ScanEvidenceJob($asset->id))->uniqueId());
    }

    private function clamReturns(string $result): void
    {
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock) use ($result): void {
            $mock->shouldReceive('scan')->andReturn($result);
        }));
    }

    private function asset(User $user, string $filename, string $bytes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'quarantined', 'scan_status' => 'pending',
            'quarantine_key' => 'quarantine/'.Str::ulid(), 'original_filename' => $filename, 'size_bytes' => strlen($bytes),
            'title' => 'Synthetic scan', 'captured_at' => now('UTC'), 'uploaded_by' => $user->id,
            'classification' => Classification::Internal, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ])->save();
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->write((string) $asset->quarantine_key, $bytes);

        return $asset;
    }
}
