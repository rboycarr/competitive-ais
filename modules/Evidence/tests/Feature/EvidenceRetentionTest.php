<?php

namespace Modules\Evidence\Tests\Feature;

use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Evidence\Actions\EvidenceBlobReconciler;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Scanning\ClamAvClient;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-38 / ADR-0007 / PO decisions O33, O38, O39: each asset is kept for its recorded source policy's retention days, or
 * the default, counted from the upload; a blob is kept for its longest-kept asset (never shortened); reconciliation
 * brings blob records and stored versions in step with the assets; the deletion check reads the store itself. The
 * object store is the real S3 adapter with the SDK's mock handler, answering as a store with object lock would (a
 * version's legal hold and governance retention, which cannot be shortened); nothing reaches a store.
 */
class EvidenceRetentionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var array<string, array{hold: bool, until: ?string}> object key => lock state of its stored version */
    private array $store = [];

    /** @var list<string> the requests that changed the store: name, key's last segment, and the value sent */
    private array $changes = [];

    /** @var list<string> request names that fail */
    private array $failing = [];

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 23:30:00', 'UTC'));
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Queue::fake();
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $disk = Storage::disk(EvidenceScanService::EVIDENCE_DISK);
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler(array_fill(0, 100, fn (CommandInterface $command): Result => new Result($this->answer($command)))));
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_an_upload_is_kept_for_its_source_policys_retention_or_the_default(): void
    {
        $long = $this->approvedSource('Long-kept filings', 400);
        $unset = $this->approvedSource('Trade news', null);
        $upload = fn (?Source $source): EvidenceAsset => app(EvidenceUploadService::class)->upload($this->analyst, UploadedFile::fake()->createWithContent('a.pdf', self::PDF), [
            'title' => 'Captured', 'captured_at' => '2025-01-01T10:00:00Z', 'classification' => 'internal', 'source' => $source?->ulid,
        ]);

        // Counted from the upload day (O39), not the capture time, which may be long past.
        $this->assertSame('2027-12-10', $upload($long)->retention_until);
        $this->assertSame('2026-12-05', $upload($unset)->retention_until, 'a policy without retention days: the default (O33)');
        $this->assertSame('2026-12-05', $upload(null)->retention_until, 'no source: the default (O33)');
    }

    public function test_a_shared_blob_is_kept_for_its_longest_kept_asset_and_never_shortened(): void
    {
        $first = $this->quarantined('2026-12-05');
        $longer = $this->quarantined('2027-12-10');
        $shorter = $this->quarantined('2026-12-31');
        $this->clamReturns(ClamAvClient::CLEAN);

        app(EvidenceScanService::class)->scan($first->id);
        $blob = EvidenceBlob::query()->sole();
        $this->assertSame('2026-12-05', $blob->retention_until?->toDateString());
        $this->assertSame(['PutObject '.$blob->sha256.' 2026-12-06T00:00:00Z'], $this->changes, 'locked through its last day, on the write itself');

        app(EvidenceScanService::class)->scan($longer->id);
        app(EvidenceScanService::class)->scan($shorter->id);
        $this->assertSame('2027-12-10', EvidenceBlob::query()->sole()->retention_until?->toDateString());
        $this->assertSame(['PutObject '.$blob->sha256.' 2026-12-06T00:00:00Z', 'PutObjectRetention '.$blob->sha256.' 2027-12-11T00:00:00Z'], $this->changes,
            'stored once, extended once, never shortened');
        $this->assertSame([$blob->id, $blob->id, $blob->id], [$first->fresh()?->evidence_blob_id, $longer->fresh()?->evidence_blob_id, $shorter->fresh()?->evidence_blob_id]);
    }

    public function test_a_failed_retention_extension_leaves_the_asset_waiting_and_its_retry_completes(): void
    {
        $first = $this->quarantined('2026-12-05');
        $longer = $this->quarantined('2027-12-10');
        $this->clamReturns(ClamAvClient::CLEAN);
        app(EvidenceScanService::class)->scan($first->id);
        $this->failing = ['PutObjectRetention'];

        $this->assertThrows(fn () => app(EvidenceScanService::class)->scan($longer->id), AwsException::class);
        $this->assertSame(['quarantined', 'error', null], [$longer->fresh()?->status, $longer->fresh()?->scan_status, $longer->fresh()?->evidence_blob_id]);
        $this->assertSame('2026-12-05', EvidenceBlob::query()->sole()->retention_until?->toDateString());
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $longer->id)->count());

        $this->failing = [];
        app(EvidenceScanService::class)->scan($longer->id); // the job's next try
        $this->assertSame('available', $longer->fresh()?->status);
        $this->assertSame('2027-12-10', DB::table('evidence_blobs')->value('retention_until'));
    }

    /** Failure injection: the try fails after the blob was stored; the retry reuses it and writes nothing again. */
    public function test_a_retry_after_the_blob_was_stored_reuses_it(): void
    {
        $asset = $this->quarantined('2026-12-05');
        $this->clamReturns(ClamAvClient::CLEAN);
        $fail = true;
        EvidenceCustodyEvent::saving(function () use (&$fail): void {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('Synthetic failure after storing.');
            }
        });

        $this->assertThrows(fn () => app(EvidenceScanService::class)->scan($asset->id), RuntimeException::class, 'Synthetic failure after storing.');
        $this->assertSame(['quarantined', 'error'], [$asset->fresh()?->status, $asset->fresh()?->scan_status]);
        $this->assertSame(1, EvidenceBlob::query()->count(), 'the blob stays, unreferenced, for the retry');

        app(EvidenceScanService::class)->scan($asset->id);
        $this->assertSame(['available', EvidenceBlob::query()->sole()->id], [$asset->fresh()?->status, $asset->fresh()?->evidence_blob_id]);
        $this->assertCount(1, $this->changes, 'the object was written once');
        $this->assertSame(['scanned'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->pluck('event')->all());
    }

    public function test_reconciliation_repairs_the_records_and_the_stored_version_from_the_assets(): void
    {
        $blob = $this->blob('2026-12-05', hold: false, store: ['hold' => false, 'until' => '2026-12-06T00:00:00Z']);
        $this->available($blob, '2027-12-10', hold: true);

        $this->assertSame(0, Artisan::call('evidence:reconcile-blobs'));
        $this->assertStringContainsString('Blobs checked: 1; repaired: 1; held without a held asset: 0; failed: 0.', Artisan::output());

        $blob = $blob->fresh();
        $this->assertSame([true, '2027-12-10'], [$blob?->legal_hold, $blob?->retention_until?->toDateString()]);
        $this->assertSame(['hold' => true, 'until' => '2027-12-11T00:00:00Z'], $this->store[(string) $blob?->storage_key]);
        $audit = DB::table('audit_events')->where('action', 'evidence.blob_reconciled')->sole();
        $this->assertSame(['system', null, EvidenceBlob::class, $blob?->id], [$audit->actor_type, $audit->actor_id, $audit->subject_type, $audit->subject_id]);
        $this->assertEquals(['record_hold' => 'on', 'retention' => 'extended', 'object_hold' => 'on'], json_decode((string) $audit->context, true));

        // In step now: nothing changes, nothing is recorded.
        $this->changes = [];
        $this->assertSame(0, Artisan::call('evidence:reconcile-blobs'));
        $this->assertSame([], $this->changes);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'evidence.blob_reconciled')->count());
    }

    public function test_a_stored_version_kept_less_than_its_record_is_extended(): void
    {
        $blob = $this->blob('2027-01-31', hold: false, store: ['hold' => false, 'until' => '2026-12-01T10:00:00Z']);
        $this->available($blob, '2027-01-31', hold: false);

        $this->assertSame(0, Artisan::call('evidence:reconcile-blobs'));
        $this->assertSame('2027-02-01T00:00:00Z', $this->store[$blob->storage_key]['until']);
        $this->assertEquals(['object_retention' => 'extended'], json_decode((string) DB::table('audit_events')->where('action', 'evidence.blob_reconciled')->value('context'), true));
    }

    /** TASK-0074: an object held while none of its assets is: reported and left held, unless the operator releases it. */
    public function test_an_object_held_without_a_held_asset_is_reported_and_released_only_on_request(): void
    {
        $blob = $this->blob('2026-12-05', hold: false, store: ['hold' => true, 'until' => '2026-12-06T00:00:00Z']);
        $this->available($blob, '2026-12-05', hold: false);

        $this->assertSame(1, Artisan::call('evidence:reconcile-blobs'));
        $this->assertStringContainsString('held without a held asset: 1', Artisan::output());
        $this->assertTrue($this->store[$blob->storage_key]['hold']);
        $this->assertEquals(['object_hold' => 'unexpected'], json_decode((string) DB::table('audit_events')->where('action', 'evidence.blob_reconciled')->value('context'), true));

        $this->assertSame(0, Artisan::call('evidence:reconcile-blobs', ['--release-unheld' => true]));
        $this->assertFalse($this->store[$blob->storage_key]['hold']);
        $this->assertEquals(['object_hold' => 'off'], json_decode((string) DB::table('audit_events')->where('action', 'evidence.blob_reconciled')->orderByDesc('id')->value('context'), true));
    }

    public function test_a_version_missing_from_the_store_fails_that_blob_only(): void
    {
        $missing = $this->blob('2026-12-05', hold: false, store: null);
        $this->available($missing, '2027-12-10', hold: false);
        $kept = $this->blob('2026-12-05', hold: false, store: ['hold' => false, 'until' => '2026-12-06T00:00:00Z']);
        $this->available($kept, '2027-12-10', hold: false);

        $this->assertSame(1, Artisan::call('evidence:reconcile-blobs'));
        $this->assertStringContainsString('Blobs checked: 2; repaired: 1; held without a held asset: 0; failed: 1.', Artisan::output());
        $this->assertSame(['2026-12-05', '2027-12-10'], [$missing->fresh()?->retention_until?->toDateString(), $kept->fresh()?->retention_until?->toDateString()],
            'the failed blob is left as it was; the other is reconciled');
    }

    /** O38: the check a deleting job must pass, in its own transaction; it reads the store, never the hold record alone. */
    public function test_a_blob_is_deletable_only_unreferenced_unheld_and_past_retention_in_the_store(): void
    {
        $reconciler = app(EvidenceBlobReconciler::class);
        $now = CarbonImmutable::now('UTC');
        $past = ['hold' => false, 'until' => '2026-10-02T00:00:00Z'];
        $referenced = $this->blob('2026-10-01', hold: false, store: $past);
        $this->available($referenced, '2026-12-05', hold: false);
        $lapsed = $this->blob('2026-10-01', hold: true, store: $past); // a stale hold record, no asset
        $heldInStore = $this->blob('2026-10-01', hold: false, store: ['hold' => true, 'until' => '2026-10-02T00:00:00Z']);
        $lockedInStore = $this->blob('2026-10-01', hold: false, store: ['hold' => false, 'until' => '2026-11-07T00:00:00Z']);
        $keptByRecord = $this->blob('2026-11-05', hold: false, store: ['hold' => false, 'until' => '2026-11-06T00:00:00Z']);

        // (Outside a transaction it is refused: EvidenceBlobRaceTest, where the test itself runs in none.)
        $verdicts = DB::transaction(fn (): array => array_map(fn (EvidenceBlob $blob): bool => $reconciler->deletable($blob->id, $now),
            [$referenced, $lapsed, $heldInStore, $lockedInStore, $keptByRecord]));

        $this->assertSame([false, true, false, false, false], $verdicts);
        $this->assertFalse($lapsed->fresh()?->legal_hold, 'reconciled first: no asset holds it');
        $this->assertTrue($this->store[$heldInStore->storage_key]['hold'], 'never released by the check');

        Storage::fake(EvidenceScanService::EVIDENCE_DISK); // a store whose lock state cannot be read: never deletable
        $this->assertFalse(DB::transaction(fn (): bool => $reconciler->deletable($lapsed->id, $now)));
    }

    public function test_the_database_holds_the_retention_rule_and_the_jobs_are_scheduled(): void
    {
        $asset = $this->quarantined('2026-12-05');
        foreach ([['retention_until' => null], ['retention_until' => '2026-11-04']] as $values) {
            try {
                DB::table('evidence_assets')->where('id', $asset->id)->update($values);
                $this->fail('Accepted: '.json_encode($values));
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[1] ?? null, [1048, 3819], $e->getMessage());
            }
        }

        $events = collect($this->app->make(Schedule::class)->events())->map(fn ($event): array => [(string) $event->command, $event->expression]);
        $this->assertTrue($events->contains(fn (array $e): bool => str_contains($e[0], 'evidence:sweep-quarantine') && $e[1] === '0 * * * *'));
        $this->assertTrue($events->contains(fn (array $e): bool => str_contains($e[0], 'evidence:reconcile-blobs') && $e[1] === '30 2 * * *'));
    }

    /**
     * Answers as a store with object lock: one version per key, its legal hold and governance retention.
     *
     * @return array<string, mixed> the result data
     */
    private function answer(CommandInterface $command): array
    {
        $name = $command->getName();
        $key = (string) ($command['Key'] ?? '');
        if (in_array($name, $this->failing, true)) {
            throw new AwsException('Synthetic store failure.', $command);
        }
        if (! isset($this->store[$key]) && $name !== 'PutObject') {
            throw new AwsException('Synthetic: no such version.', $command, ['code' => 'NoSuchVersion']);
        }

        switch ($name) {
            case 'PutObject':
                $this->store[$key] = ['hold' => false, 'until' => (string) $command['ObjectLockRetainUntilDate']];
                $this->changes[] = 'PutObject '.basename($key).' '.$command['ObjectLockRetainUntilDate'];

                return ['VersionId' => 'synthetic-version'];
            case 'PutObjectRetention':
                $until = (string) $command['Retention']['RetainUntilDate'];
                if ($this->store[$key]['until'] !== null && $until < $this->store[$key]['until']) {
                    throw new AwsException('Synthetic: governance retention cannot be shortened.', $command, ['code' => 'AccessDenied']);
                }
                $this->store[$key]['until'] = $until;
                $this->changes[] = 'PutObjectRetention '.basename($key).' '.$until;

                return [];
            case 'PutObjectLegalHold':
                $this->store[$key]['hold'] = $command['LegalHold']['Status'] === 'ON';
                $this->changes[] = 'PutObjectLegalHold '.basename($key).' '.$command['LegalHold']['Status'];

                return [];
            case 'GetObjectLegalHold':
                return ['LegalHold' => ['Status' => $this->store[$key]['hold'] ? 'ON' : 'OFF']];
            case 'GetObjectRetention':
                if ($this->store[$key]['until'] === null) {
                    throw new AwsException('Synthetic: no retention.', $command, ['code' => 'NoSuchObjectLockConfiguration']);
                }

                return ['Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => new DateTimeResult($this->store[$key]['until'])]];
        }

        throw new RuntimeException("Unexpected store request $name.");
    }

    private function approvedSource(string $name, ?int $retentionDays): Source
    {
        $director = User::query()->where('email', 'director@example.test')->first()
            ?? $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $source = app(SourceService::class)->propose($this->analyst, ['name' => $name, 'source_type' => 'website', 'classification' => 'internal']);
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        app(SourcePolicyService::class)->decide($director, $source, [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => '2026-11-01',
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false, 'retention_days' => $retentionDays,
        ]);

        return $source->refresh();
    }

    private function clamReturns(string $result): void
    {
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, function ($mock) use ($result): void {
            $mock->shouldReceive('scan')->andReturn($result);
        }));
    }

    private function quarantined(string $retentionUntil): EvidenceAsset
    {
        $asset = $this->asset(['status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid(), 'retention_until' => $retentionUntil]);
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->put((string) $asset->quarantine_key, self::PDF);

        return $asset;
    }

    private function available(EvidenceBlob $blob, string $retentionUntil, bool $hold): EvidenceAsset
    {
        return $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob->id, 'legal_hold' => $hold, 'retention_until' => $retentionUntil]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill($attributes + [
            'ulid' => (string) Str::ulid(), 'original_filename' => 'report.pdf', 'size_bytes' => strlen(self::PDF), 'title' => 'Synthetic retention',
            'captured_at' => now(), 'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal,
        ])->save();

        return $asset;
    }

    /** @param  ?array{hold: bool, until: ?string}  $store  the stored version's lock state; null: missing from the store */
    private function blob(string $retentionUntil, bool $hold, ?array $store): EvidenceBlob
    {
        $sha256 = hash('sha256', (string) Str::ulid());
        $key = 'sha256/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
        $id = DB::table('evidence_blobs')->insertGetId([
            'sha256' => $sha256, 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => $key, 'object_version_id' => 'synthetic-version',
            'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd', 'first_scanned_at' => now(),
            'retention_until' => $retentionUntil, 'legal_hold' => $hold, 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        if ($store !== null) {
            $this->store[$key] = $store;
        }

        return EvidenceBlob::query()->findOrFail($id);
    }
}
