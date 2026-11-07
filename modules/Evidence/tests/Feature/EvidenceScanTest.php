<?php

namespace Modules\Evidence\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Scanning\ClamAvClient;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-32b / ADR-0007 / docs/02 §3.5: a quarantined asset is scanned, stored once if clean, and rejected if infected or
 * no longer matching its extension (PO decision O34); results are written only from a pending scan, so a terminal
 * result is never overwritten and a duplicate or retried job is harmless. The database holds the blob rules.
 */
class EvidenceScanTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /**
     * The official 68-byte EICAR test string, base64-encoded so no editor or scanner flags this file itself. Decoded
     * only in memory, never written to the fake quarantine disk: that disk resolves to a path under the project
     * directory, which on a Windows host (even reached through WSL) sits on the NTFS volume real-time antivirus
     * protection scans. The genuine bytes were found, written, and left readable on disk (`file_exists()` true), yet
     * `readStream()` failed every time with `UnableToReadFile`: the read itself was blocked, consistent with on-access
     * scanning rather than a code defect (TASK-0069 rework, 2026-11-04). `test_the_eicar_fixture_decodes_correctly`
     * proves this constant is the genuine string without touching disk; `test_the_exact_standard_eicar_fixture_...`
     * below writes a safe placeholder instead, since ClamAvClient is mocked and never actually reads the bytes
     * either. The real string is exercised only by the real-ClamAV smoke test, over an in-memory stream (never a
     * file), recorded in the session history.
     *
     * An earlier, 4-byte-short version of this constant (missing "CC)7") went undetected by the real ClamAV while
     * every automated test still passed: a reminder that a mocked verdict proves the service's handling of that
     * verdict, never that a fixture is the real thing.
     */
    private const EICAR = 'WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=';

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        $this->analyst = $this->createUser('scanner@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_clean_content_is_promoted_and_stored_once(): void
    {
        $asset = $this->quarantined('report.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);

        app(EvidenceScanService::class)->scan($asset->id);

        $asset = $asset->fresh();
        $this->assertNotNull($asset);
        $this->assertSame(['available', 'clean', null], [$asset->status, $asset->scan_status, $asset->quarantine_key]);
        $this->assertNotNull($asset->scanned_at);
        $this->assertSame('clamd', $asset->scan_engine);
        $this->assertNotNull($asset->evidence_blob_id);

        $blob = EvidenceBlob::query()->sole();
        $this->assertSame([hash('sha256', self::PDF), 'evidence', strlen(self::PDF), 'application/pdf', 'ok', false], [
            $blob->sha256, $blob->storage_disk, $blob->size_bytes, $blob->detected_mime, $blob->integrity_status, $blob->legal_hold,
        ]);
        $this->assertSame(now('UTC')->addDays(30)->toDateString(), $blob->retention_until?->toDateString());
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->assertExists($blob->storage_key);
        $this->assertSame(self::PDF, Storage::disk(EvidenceScanService::EVIDENCE_DISK)->get($blob->storage_key));
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());

        $events = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['scanned'], $events);
        $audit = DB::table('audit_events')->where('action', 'evidence.scanned')->sole();
        $this->assertSame(['system', null], [$audit->actor_type, $audit->actor_id]);
        $this->assertEquals(['result' => 'clean', 'sha256' => $blob->sha256], json_decode((string) $audit->context, true));
    }

    public function test_identical_bytes_reuse_the_same_blob(): void
    {
        $first = $this->quarantined('one.pdf', self::PDF);
        $second = $this->quarantined('two.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);

        app(EvidenceScanService::class)->scan($first->id);
        app(EvidenceScanService::class)->scan($second->id);

        $this->assertSame(1, EvidenceBlob::query()->count());
        $blobId = EvidenceBlob::query()->sole()->id;
        $this->assertSame([$blobId, $blobId], [$first->fresh()?->evidence_blob_id, $second->fresh()?->evidence_blob_id]);
        $this->assertCount(1, Storage::disk(EvidenceScanService::EVIDENCE_DISK)->allFiles());
    }

    /**
     * P1-33: the same bytes uploaded twice, by different people from different sources, are stored once, yet each
     * asset keeps its own provenance (title, source, capture time, uploader, classification), its own custody trail
     * and audit records, and its own visibility: sharing a blob never widens who sees an asset.
     */
    public function test_shared_bytes_keep_each_assets_own_provenance(): void
    {
        Bus::fake();
        $colleague = $this->createUser('colleague@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $reader = $this->createUser('reader@example.test', 'ci_analyst', clearance: Classification::Internal);
        $uploads = app(EvidenceUploadService::class);
        $first = $uploads->upload($this->analyst, UploadedFile::fake()->createWithContent('shelf.pdf', self::PDF), [
            'title' => 'Shelf photo list', 'source_url' => 'https://shop-a.example/list', 'captured_at' => '2026-11-01T09:00:00Z', 'classification' => 'internal',
        ]);
        $second = $uploads->upload($colleague, UploadedFile::fake()->createWithContent('brochure.pdf', self::PDF), [
            'title' => 'Trade brochure', 'source_url' => 'https://fair-b.example/brochure', 'captured_at' => '2026-11-02T15:30:00Z', 'classification' => 'confidential',
        ]);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($first->id);
        app(EvidenceScanService::class)->scan($second->id);

        $blob = EvidenceBlob::query()->sole();
        $this->assertCount(1, Storage::disk(EvidenceScanService::EVIDENCE_DISK)->allFiles());
        $assets = EvidenceAsset::query()->orderBy('id')->get();
        $this->assertSame([
            [$blob->id, 'available', 'shelf.pdf', 'Shelf photo list', 'https://shop-a.example/list', '2026-11-01 09:00:00', $this->analyst->id, Classification::Internal],
            [$blob->id, 'available', 'brochure.pdf', 'Trade brochure', 'https://fair-b.example/brochure', '2026-11-02 15:30:00', $colleague->id, Classification::Confidential],
        ], $assets->map(fn (EvidenceAsset $a): array => [
            $a->evidence_blob_id, $a->status, $a->original_filename, $a->title, $a->source_url, $a->captured_at->format('Y-m-d H:i:s'), $a->uploaded_by, $a->classification,
        ])->all());

        foreach ([[$first, $this->analyst], [$second, $colleague]] as [$asset, $uploader]) {
            $trail = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->orderBy('id')->get()
                ->map(fn (EvidenceCustodyEvent $e): array => [$e->event, $e->actor_type, $e->actor_id])->all();
            $this->assertSame([['uploaded', 'user', $uploader->id], ['scanned', 'system', null]], $trail);
            $audit = DB::table('audit_events')->where('subject_ulid', $asset->ulid)->orderBy('id')->get(['action', 'actor_id'])
                ->map(fn (object $row): array => [$row->action, $row->actor_id])->all();
            $this->assertEquals([['evidence.uploaded', $uploader->id], ['evidence.scanned', null]], $audit);
        }

        $seen = fn (User $viewer): array => EvidenceAsset::seenBy(EvidenceAsset::query(), $viewer, app(Gate::class))->orderBy('id')->pluck('id')->all();
        $this->assertSame([$first->id], $seen($reader));
        $this->assertSame([$first->id, $second->id], $seen($colleague));
    }

    /** P1-48 (TASK-0094 #2): bytes whose stored copy a re-hash found bad are never made available on that copy. */
    public function test_identical_bytes_are_rejected_when_their_stored_copy_failed_its_integrity_check(): void
    {
        $first = $this->quarantined('one.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($first->id);
        DB::table('evidence_blobs')->update(['integrity_status' => 'mismatch']);

        $second = $this->quarantined('two.pdf', self::PDF);
        app(EvidenceScanService::class)->scan($second->id);

        $second = $second->fresh();
        $this->assertNotNull($second);
        $this->assertSame(['rejected', 'clean', null, null], [$second->status, $second->scan_status, $second->quarantine_key, $second->evidence_blob_id]);
        $this->assertSame(['scanned', 'rejected'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $second->id)->orderBy('id')->pluck('event')->all());
        $audit = DB::table('audit_events')->where('action', 'evidence.rejected')->sole();
        $this->assertSame([$second->ulid, 'stored_copy_failed'], [$audit->subject_ulid, json_decode((string) $audit->context, true)['reason']]);
        $this->assertSame(1, EvidenceBlob::query()->count());
    }

    public function test_a_type_mismatch_at_scan_time_is_rejected_outright(): void
    {
        // The upload service would have refused this at the door; this is defence in depth if the stored bytes ever
        // stop matching the declared extension (O34, 2026-11-03: reject outright, never re-quarantine).
        $asset = $this->quarantined('spoof.png', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);

        app(EvidenceScanService::class)->scan($asset->id);

        $asset = $asset->fresh();
        $this->assertNotNull($asset);
        $this->assertSame(['rejected', 'clean', null, null], [$asset->status, $asset->scan_status, $asset->quarantine_key, $asset->evidence_blob_id]);
        $this->assertSame(0, EvidenceBlob::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
        $events = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['scanned', 'rejected'], $events);
        $audit = DB::table('audit_events')->where('action', 'evidence.rejected')->sole();
        $this->assertSame(['type_mismatch'], [json_decode((string) $audit->context, true)['reason']]);
    }

    public function test_the_eicar_fixture_decodes_to_the_genuine_signature(): void
    {
        $eicar = base64_decode(self::EICAR, true);
        $this->assertSame(68, strlen((string) $eicar));
        $this->assertSame('X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*', $eicar);
    }

    public function test_the_exact_standard_eicar_fixture_is_rejected_as_infected(): void
    {
        // Not the real EICAR bytes on disk (see the constant's docblock): ClamAvClient is mocked below and never
        // reads them, so a safe placeholder exercises the same "infected" code path without the real bytes landing
        // on the host's antivirus-scanned filesystem.
        $asset = $this->quarantined('eicar.txt', 'Synthetic placeholder: the real content is never read (mocked scanner).');
        $this->clamReturns(ClamAvClient::INFECTED);

        app(EvidenceScanService::class)->scan($asset->id);

        $asset = $asset->fresh();
        $this->assertNotNull($asset);
        $this->assertSame(['rejected', 'infected', null], [$asset->status, $asset->scan_status, $asset->evidence_blob_id]);
        $this->assertSame(0, EvidenceBlob::query()->count());
        $events = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['scanned', 'rejected'], $events);
        $audit = DB::table('audit_events')->where('action', 'evidence.rejected')->sole();
        $this->assertSame(['infected'], [json_decode((string) $audit->context, true)['reason']]);
    }

    public function test_a_scanner_error_is_retryable_and_leaves_the_asset_in_quarantine(): void
    {
        $asset = $this->quarantined('retry.pdf', self::PDF);
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andThrow(new RuntimeException('Synthetic scanner failure.'));
        }));
        $log = Log::spy();

        $this->assertThrows(fn () => app(EvidenceScanService::class)->scan($asset->id), RuntimeException::class, 'Synthetic scanner failure.');

        $asset = $asset->fresh();
        $this->assertNotNull($asset);
        $this->assertSame(['quarantined', 'error'], [$asset->status, $asset->scan_status]);
        $this->assertNotNull($asset->quarantine_key);
        $this->assertNotNull($asset->scanned_at);
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists((string) $asset->quarantine_key);
        $log->shouldHaveReceived('warning')->once()->with('evidence.scan_error', ['asset_id' => $asset->id, 'error' => RuntimeException::class]);

        // The retry (ScanEvidenceJob's next attempt) claims it again: error -> pending -> clean.
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($asset->id);
        $this->assertSame(['available', 'clean'], [$asset->fresh()?->status, $asset->fresh()?->scan_status]);
    }

    public function test_a_terminal_result_is_never_overwritten_and_a_duplicate_job_is_harmless(): void
    {
        $asset = $this->quarantined('once.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($asset->id);
        $decided = $asset->fresh();
        $this->assertNotNull($decided);

        // A second call (a duplicate ScanEvidenceJob dispatch, or a retry that arrives late) changes nothing: the
        // asset is no longer quarantined, so claim() returns null before anything else runs.
        app(EvidenceScanService::class)->scan($asset->id);

        $this->assertSame($decided->toArray(), $asset->fresh()?->toArray());
        $this->assertSame(1, EvidenceBlob::query()->count());
        $this->assertSame(1, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
    }

    public function test_scanning_an_asset_that_is_not_quarantined_or_missing_does_nothing(): void
    {
        $available = $this->quarantined('clean.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($available->id);

        // Already decided: no scan should run again (would throw if ClamAvClient::scan() were called).
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock): void {
            $mock->shouldNotReceive('scan');
        }));
        app(EvidenceScanService::class)->scan($available->id);
        app(EvidenceScanService::class)->scan(999999); // an unknown asset id
    }

    public function test_scan_evidence_job_is_configured_for_retries_and_uniqueness(): void
    {
        $job = new ScanEvidenceJob(42);
        $this->assertSame(['evidence', 3, [30, 120, 600], '42'], [$job->queue, $job->tries, $job->backoff(), $job->uniqueId()]);
        // TASK-0071: the unique lock outlives the longest job (3 × timeout + backoff), but lapses within 30 minutes if
        // its worker dies, so evidence:dispatch-scans can recover the scan.
        $this->assertGreaterThan(3 * $job->timeout + array_sum($job->backoff()), $job->uniqueFor);
        $this->assertLessThanOrEqual(1800, $job->uniqueFor);
    }

    public function test_the_database_holds_the_blob_rules(): void
    {
        $row = fn (array $overrides = []): array => $overrides + [
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => 'evidence', 'storage_key' => 'sha256/'.Str::random(60),
            'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd', 'first_scanned_at' => now(),
            'integrity_status' => 'ok', 'created_at' => now(),
        ];
        foreach ([
            ['sha256' => 'not-a-hash'], ['sha256' => str_repeat('G', 64)], ['size_bytes' => 0],
            ['detected_mime' => '  '], ['integrity_status' => 'corrupt'],
        ] as $overrides) {
            $this->assertDatabaseError(3819, fn () => DB::table('evidence_blobs')->insert($row($overrides)), (string) json_encode($overrides));
        }
        $id = DB::table('evidence_blobs')->insertGetId($row());
        $this->assertDatabaseError(1062, fn () => DB::table('evidence_blobs')->insert($row(['sha256' => DB::table('evidence_blobs')->where('id', $id)->value('sha256')])), 'duplicate sha256');

        // evidence_assets.evidence_blob_id: exactly when the status is available (TASK-0069 rework). Isolated from the
        // older state check by setting every other "available" column correctly first.
        $asset = $this->quarantined('probe.pdf', self::PDF);
        $this->assertDatabaseError(3819, fn () => DB::table('evidence_assets')->where('id', $asset->id)
            ->update(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'quarantine_key' => null]), 'available without a blob');
        DB::table('evidence_assets')->where('id', $asset->id)->update(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'quarantine_key' => null, 'evidence_blob_id' => $id]);
        $this->assertDatabaseError(3819, fn () => DB::table('evidence_assets')->where('id', $asset->id)->update(['evidence_blob_id' => null]), 'available without a blob (cleared)');

        // Never deleted, by the app account.
        $this->assertDatabaseError(1142, fn () => DB::table('evidence_blobs')->where('id', $id)->delete(), 'blob delete');
    }

    public function test_rollback_is_refused_while_blobs_exist(): void
    {
        $asset = $this->quarantined('kept.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($asset->id);

        $migration = require base_path('modules/Evidence/database/migrations/2026_11_19_000100_create_evidence_blobs_table.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'Evidence blobs exist');
        $this->assertSame(1, EvidenceBlob::query()->count());
    }

    /**
     * TASK-0070 #2: scans of identical bytes are serialised by a named lock per hash, so only one writes the object.
     * Another session holding the lock (a concurrent scan of the same bytes) makes this scan wait, then fail and
     * retry, with nothing written; once the lock is free, the retry stores the bytes once.
     */
    public function test_a_scan_waits_for_another_storing_the_same_bytes(): void
    {
        config(['evidence.blob_lock_seconds' => 1, 'database.connections.lockholder' => config('database.connections.mysql')]);
        $holder = DB::connection('lockholder');
        $this->assertSame(1, (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [EvidenceScanService::blobLockName(hash('sha256', self::PDF))])->acquired);

        $asset = $this->quarantined('locked.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        try {
            $this->assertThrows(fn () => app(EvidenceScanService::class)->scan($asset->id), RuntimeException::class, 'Another scan is still storing the same bytes.');
            $this->assertSame(['quarantined', 'error'], [$asset->fresh()?->status, $asset->fresh()?->scan_status]);
            $this->assertSame(0, EvidenceBlob::query()->count());
            $this->assertSame([], Storage::disk(EvidenceScanService::EVIDENCE_DISK)->allFiles());
        } finally {
            $holder->select('SELECT RELEASE_LOCK(?)', [EvidenceScanService::blobLockName(hash('sha256', self::PDF))]);
            DB::purge('lockholder');
        }

        app(EvidenceScanService::class)->scan($asset->id);
        $this->assertSame('available', $asset->fresh()?->status);
        $this->assertCount(1, Storage::disk(EvidenceScanService::EVIDENCE_DISK)->allFiles());
    }

    /** TASK-0070 #1: an asset left waiting (a lost dispatch, a dead worker) has its scan queued again; others do not. */
    public function test_scans_left_waiting_are_queued_again_on_a_schedule(): void
    {
        $stale = $this->quarantined('stale.pdf', self::PDF);
        $this->quarantined('fresh.pdf', self::PDF); // pending, but not yet stale
        $failed = $this->quarantined('failed.pdf', self::PDF);
        $done = $this->quarantined('done.pdf', self::PDF);
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($done->id);
        $old = now('UTC')->subMinutes(16);
        DB::table('evidence_assets')->whereIn('id', [$stale->id, $done->id])->update(['updated_at' => $old]);
        DB::table('evidence_assets')->where('id', $failed->id)->update(['scan_status' => 'error', 'scanned_at' => $old, 'updated_at' => $old]);
        Bus::fake();

        $this->assertSame(0, Artisan::call('evidence:dispatch-scans'));

        Bus::assertDispatchedTimes(ScanEvidenceJob::class, 1);
        Bus::assertDispatched(ScanEvidenceJob::class, fn (ScanEvidenceJob $job): bool => $job->assetId === $stale->id);
        $events = collect($this->app->make(Schedule::class)->events())->map(fn ($event): array => [(string) $event->command, $event->expression]);
        $this->assertTrue($events->contains(fn (array $e): bool => str_contains($e[0], 'evidence:dispatch-scans') && $e[1] === '*/5 * * * *'));
    }

    /** TASK-0071: a dispatch that fails for one asset does not stop the others; the command reports the failure. */
    public function test_one_failed_dispatch_does_not_stop_the_sweep(): void
    {
        $first = $this->quarantined('first.pdf', self::PDF);
        $second = $this->quarantined('second.pdf', self::PDF);
        DB::table('evidence_assets')->update(['updated_at' => now('UTC')->subMinutes(16)]);
        $dispatched = [];
        app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock) use ($first, &$dispatched): void {
            $mock->shouldReceive('dispatch')->twice()->andReturnUsing(function (ScanEvidenceJob $job) use ($first, &$dispatched): void {
                if ($job->assetId === $first->id) {
                    throw new RuntimeException('Synthetic queue failure.');
                }
                $dispatched[] = $job->assetId;
            });
        }));

        $this->assertSame(1, Artisan::call('evidence:dispatch-scans'));
        $this->assertSame([$second->id], $dispatched);
        $this->assertStringContainsString('failed to queue: 1', Artisan::output());
    }

    private function clamReturns(string $result): void
    {
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock) use ($result): void {
            $mock->shouldReceive('scan')->andReturn($result);
        }));
    }

    private function quarantined(string $filename, string $bytes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'quarantined', 'scan_status' => 'pending',
            'quarantine_key' => 'quarantine/'.Str::ulid(), 'original_filename' => $filename, 'size_bytes' => strlen($bytes),
            'title' => 'Synthetic scan', 'captured_at' => now('UTC'), 'uploaded_by' => $this->analyst->id,
            'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ])->save();
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->put((string) $asset->quarantine_key, $bytes);

        return $asset;
    }

    private function assertDatabaseError(int $code, callable $statement, string $case): void
    {
        try {
            $statement();
            $this->fail("Expected database error $code for: $case");
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[1] ?? null, "$case: {$e->getMessage()}");
        }
    }
}
