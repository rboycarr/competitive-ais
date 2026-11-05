<?php

namespace Modules\Evidence\Tests\Feature;

use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Evidence\Actions\EvidenceDownloadService;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-34 / ADR-0007 / docs/07 T1, T7: evidence.download holders who see an available asset get an attachment-only link
 * to its stored version, valid at most 60 seconds, recorded as a `downloaded` custody event and an audit event; anyone
 * else, and anything not available or in doubt, gets no link and leaves no record. Links are captured from the fake
 * disk; the S3 signing itself is tested in PrivateObjectStorageTest.
 */
class EvidenceDownloadTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    /** @var list<array{path: string, expires: DateTimeInterface, options: array<string, mixed>}> */
    private array $links = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        // By reference: Laravel binds this callback to the disk, so `$this` inside it is not the test.
        $links = &$this->links;
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expires, array $options) use (&$links): string {
            $links[] = ['path' => $path, 'expires' => $expires, 'options' => $options];

            return 'https://files.example.test/'.$path.'?signed=synthetic';
        });
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_a_permitted_user_gets_a_short_attachment_link_and_the_download_is_recorded(): void
    {
        $asset = $this->asset('available', 'Price list "Q4" — Nord.pdf', versionId: 'synthetic-version-1');
        $blob = DB::table('evidence_blobs')->where('id', $asset->evidence_blob_id)->first();
        $this->assertNotNull($blob);

        $this->freezeTime();
        $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/download")
            ->assertRedirect('https://files.example.test/'.$blob->storage_key.'?signed=synthetic');

        $this->assertCount(1, $this->links);
        $link = $this->links[0];
        $this->assertSame($blob->storage_key, $link['path']);
        $this->assertSame(60, (int) now()->diffInSeconds($link['expires']));
        $this->assertSame([
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => "attachment; filename=\"Price list _Q4_ _ Nord.pdf\"; filename*=utf-8''Price%20list%20_Q4_%20%E2%80%94%20Nord.pdf",
            'VersionId' => 'synthetic-version-1',
        ], $link['options']);

        $event = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->sole();
        $this->assertSame(['downloaded', 'user', $this->analyst->id], [$event->event, $event->actor_type, $event->actor_id]);
        $audit = DB::table('audit_events')->where('action', 'evidence.downloaded')->sole();
        $this->assertSame(['user', $this->analyst->id, $asset->ulid], [$audit->actor_type, $audit->actor_id, $audit->subject_ulid]);
        $this->assertEquals(['sha256' => $blob->sha256], json_decode((string) $audit->context, true));
    }

    public function test_an_inertia_visit_is_sent_to_the_link_as_a_full_page_visit(): void
    {
        $asset = $this->asset('available');
        $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/download", [], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location');
    }

    public function test_the_link_is_never_longer_than_sixty_seconds(): void
    {
        config(['evidence.download_seconds' => 3600]);
        $asset = $this->asset('available');
        $this->freezeTime();
        app(EvidenceDownloadService::class)->link($this->analyst, $asset);
        $this->assertSame(60, (int) now()->diffInSeconds($this->links[0]['expires']));
    }

    public function test_without_evidence_download_there_is_no_link_and_no_record(): void
    {
        $asset = $this->asset('available');
        $collector = $this->createUser('collector@example.test', 'data_collector', clearance: Classification::Confidential);

        $this->actingAs($collector)->post("/evidence/{$asset->ulid}/download")->assertForbidden();
        $this->assertThrows(fn () => app(EvidenceDownloadService::class)->link($collector, $asset), AuthorizationException::class);
        $this->assertNothingIssued($asset);
    }

    public function test_a_hidden_asset_is_not_found_and_leaves_no_record(): void
    {
        $asset = $this->asset('available', classification: Classification::Confidential);
        $reader = $this->createUser('reader@example.test', 'ci_analyst', clearance: Classification::Internal);

        $this->actingAs($reader)->post("/evidence/{$asset->ulid}/download")->assertNotFound();
        $this->actingAs($reader)->post('/evidence/'.Str::ulid().'/download')->assertNotFound();
        $this->assertNothingIssued($asset);
    }

    public function test_only_available_evidence_with_intact_bytes_is_served(): void
    {
        $cases = [
            'quarantined (own upload, visible)' => $this->asset('quarantined'),
            'rejected (own upload, visible)' => $this->asset('rejected'),
            'withdrawn' => $this->asset('withdrawn'),
            'integrity mismatch' => $this->asset('available', integrity: 'mismatch'),
        ];
        foreach ($cases as $case => $asset) {
            $this->actingAs($this->analyst)->from("/evidence/{$asset->ulid}")->post("/evidence/{$asset->ulid}/download")
                ->assertRedirect("/evidence/{$asset->ulid}")->assertSessionHasErrors('download');
            $this->assertThrows(fn () => app(EvidenceDownloadService::class)->link($this->analyst, $asset), ValidationException::class);
            $this->assertNothingIssued($asset, $case);
        }
    }

    /** TASK-0073: a link without the version would serve the latest version, not necessarily the stored one. */
    public function test_a_blob_without_its_stored_version_is_not_served_outside_tests(): void
    {
        $asset = $this->asset('available');
        $this->app['env'] = 'production';
        try {
            app(EvidenceDownloadService::class)->link($this->analyst, $asset);
            $this->fail('A blob without its stored version was served.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('stored version', (string) collect($e->errors())->flatten()->first());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertNothingIssued($asset);
    }

    public function test_a_get_or_a_guest_gets_nothing(): void
    {
        $asset = $this->asset('available');
        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}/download")->assertMethodNotAllowed();
        auth()->logout();
        $this->post("/evidence/{$asset->ulid}/download")->assertRedirect('/login');
        $this->assertNothingIssued($asset);
    }

    public function test_a_failed_record_hands_out_no_link(): void
    {
        $asset = $this->asset('available');
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure.'));

        $this->assertThrows(fn () => app(EvidenceDownloadService::class)->link($this->analyst, $asset), RuntimeException::class, 'Synthetic audit failure.');
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
    }

    public function test_downloads_are_rate_limited_per_user(): void
    {
        $asset = $this->asset('available');
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/download")->assertRedirect();
        }
        $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/download")->assertTooManyRequests();
        $this->assertSame(30, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
    }

    public function test_the_detail_page_offers_the_download_only_where_it_would_succeed(): void
    {
        $available = $this->asset('available');
        $quarantined = $this->asset('quarantined');
        $collector = $this->createUser('collector@example.test', 'data_collector', clearance: Classification::Confidential);

        $offered = fn (User $user, EvidenceAsset $asset): bool => (bool) $this->actingAs($user)->get("/evidence/{$asset->ulid}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('canDownload'))->inertiaProps('canDownload');
        $this->assertSame([true, false, false], [$offered($this->analyst, $available), $offered($this->analyst, $quarantined), $offered($collector, $available)]);
    }

    private function assertNothingIssued(EvidenceAsset $asset, string $case = ''): void
    {
        $this->assertSame([], $this->links, $case);
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->where('event', 'downloaded')->count(), $case);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'evidence.downloaded')->count(), $case);
    }

    private function asset(string $status, string $filename = 'report.pdf', ?string $versionId = null, string $integrity = 'ok', Classification $classification = Classification::Internal): EvidenceAsset
    {
        $blobId = null;
        if ($status === 'available') {
            $key = 'sha256/'.Str::random(60);
            $blobId = (int) DB::table('evidence_blobs')->insertGetId([
                'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => $key,
                'object_version_id' => $versionId, 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
                'first_scanned_at' => now(), 'integrity_status' => $integrity, 'created_at' => now(),
            ]);
            Storage::disk(EvidenceScanService::EVIDENCE_DISK)->put($key, 'Synthetic bytes.');
        }
        $scan = match ($status) {
            'quarantined' => 'pending',
            'rejected' => 'infected',
            default => 'clean',
        };
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => $status, 'scan_status' => $scan, 'scanned_at' => $scan === 'pending' ? null : now(),
            'quarantine_key' => $status === 'quarantined' ? 'quarantine/'.Str::ulid() : null, 'evidence_blob_id' => $blobId,
            'original_filename' => $filename, 'size_bytes' => 10, 'title' => 'Synthetic download', 'captured_at' => now(),
            'uploaded_by' => $this->analyst->id, 'classification' => $classification,
        ])->save();

        return $asset;
    }
}
