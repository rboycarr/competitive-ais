<?php

namespace Modules\Evidence\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
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
use RuntimeException;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-38 / docs/09 #15, with committed data and separate processes (Concurrently): duplicate uploads whose scan jobs run
 * at the same time, one of them found infected, end with one blob and one stored object, shared by the clean copies
 * and kept for the longest of them; the infected copy touches no blob; a retried job changes nothing. Also with
 * committed data: an upload stalled inside its transaction keeps its quarantine object through a sweep (TASK-0082 #1),
 * the retention migration's backfill and rollback, and the deletion check refusing to run outside a transaction.
 * DatabaseMigrations, so tearDown removes the evidence and source rows (the app account cannot, and the migrations
 * refuse to roll back while they exist).
 */
class EvidenceBlobRaceTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const MIGRATION = 'modules/Evidence/database/migrations/2026_11_23_000100_add_retention_to_evidence_assets.php';

    protected function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        $migrator->table('evidence_custody_events')->delete();
        $migrator->table('evidence_assets')->delete();
        $migrator->table('evidence_blobs')->delete();
        $migrator->table('sources')->update(['current_policy_id' => null, 'status' => 'proposed']);
        $migrator->table('source_policies')->delete();
        $migrator->table('sources')->delete();
        DB::disconnect('migrator');

        parent::tearDown();
    }

    public function test_duplicate_uploads_scanned_at_once_share_one_blob_and_an_infected_copy_touches_none(): void
    {
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $day = fn (int $days): string => CarbonImmutable::now('UTC')->addDays($days)->toDateString();
        $shorter = $this->quarantined($analyst, $day(30));
        $longer = $this->quarantined($analyst, $day(400));
        $infected = $this->quarantined($analyst, $day(800)); // kept longest, but never stored

        $scan = fn (EvidenceAsset $asset, string $verdict): callable => function () use ($asset, $verdict): void {
            app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn($verdict)));
            app(EvidenceScanService::class)->scan($asset->id);
        };
        $results = Concurrently::run([$scan($shorter, ClamAvClient::CLEAN), $scan($longer, ClamAvClient::CLEAN), $scan($infected, ClamAvClient::INFECTED)], 90.0);

        $this->assertSame(['ok', 'ok', 'ok'], array_column($results, 'outcome'), json_encode($results) ?: '');
        $blob = EvidenceBlob::query()->sole();
        $this->assertSame(hash('sha256', self::PDF), $blob->sha256);
        $this->assertSame($day(400), $blob->retention_until?->toDateString(), 'kept for the longest-kept clean copy');
        $this->assertSame([['available', $blob->id], ['available', $blob->id], ['rejected', null]],
            array_map(fn (EvidenceAsset $a): array => [$a->fresh()?->status, $a->fresh()?->evidence_blob_id], [$shorter, $longer, $infected]));
        $this->assertSame([$blob->storage_key], Storage::disk(EvidenceScanService::EVIDENCE_DISK)->allFiles());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());

        // A retried or duplicate job, after the race: nothing changes.
        app()->instance(ClamAvClient::class, Mockery::mock(ClamAvClient::class, fn ($mock) => $mock->shouldNotReceive('scan')));
        app(EvidenceScanService::class)->scan($longer->id);
        $this->assertSame(1, EvidenceBlob::query()->count());
        $this->assertSame(1, EvidenceCustodyEvent::query()->where('evidence_asset_id', $longer->id)->count());
    }

    /**
     * TASK-0082 #1: the upload is held inside its transaction (after the write, before the commit) while the sweep runs
     * with every object counting as old; the sweep finds no committed asset, but the upload's lock keeps it off the key.
     * Without that lock, the sweep deletes the object and the upload then commits an asset without one.
     */
    public function test_a_sweep_during_a_stalled_upload_leaves_its_object(): void
    {
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Queue::fake();
        config(['evidence.quarantine_orphan_minutes' => -5]); // every object counts as old
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $stalled = sys_get_temp_dir().'/cais-p138-stalled-'.bin2hex(random_bytes(6));
        $swept = "$stalled.swept";
        EvidenceCustodyEvent::saving(function () use ($stalled, $swept): void {
            touch($stalled); // inside the upload's transaction, its object already written
            self::until(fn (): bool => is_file($swept), 'The sweep never ran.');
        });

        try {
            $results = Concurrently::run([
                fn () => app(EvidenceUploadService::class)->upload($analyst, UploadedFile::fake()->createWithContent('a.pdf', self::PDF),
                    ['title' => 'Stalled', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal']),
                function () use ($stalled, $swept): void {
                    self::until(fn (): bool => is_file($stalled), 'The upload never reached its transaction.');
                    $code = Artisan::call('evidence:sweep-quarantine');
                    file_put_contents($swept, $code.' '.Artisan::output());
                },
            ], 60.0);
            $sweep = (string) file_get_contents($swept);
        } finally {
            foreach ([$stalled, $swept] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        $this->assertSame(['ok', 'ok'], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertStringContainsString('removed: 0; failed: 0; in use by an upload: 1;', $sweep);
        $asset = EvidenceAsset::query()->sole();
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists((string) $asset->quarantine_key);
    }

    /** Polls a condition in a task process, failing after 30 seconds. */
    private static function until(callable $condition, string $failure): void
    {
        $deadline = microtime(true) + 30;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException($failure);
            }
            usleep(50_000);
        }
    }

    /** The migration fills existing assets by the upload rule, from their creation time; rollback and re-apply agree. */
    public function test_the_migration_derives_retention_for_existing_evidence(): void
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $source = app(SourceService::class)->propose($analyst, ['name' => 'Long-kept filings', 'source_type' => 'website', 'classification' => 'internal']);
        app(SourcePolicyService::class)->submit($analyst, $source);
        app(SourcePolicyService::class)->decide($director, $source, [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false, 'retention_days' => 400,
        ]);
        $withPolicy = $this->quarantined($analyst, '2099-01-01');
        DB::connection('migrator')->table('evidence_assets')->where('id', $withPolicy->id)
            ->update(['source_id' => $source->id, 'source_policy_id' => $source->refresh()->current_policy_id, 'created_at' => '2026-03-01 10:00:00']);
        $without = $this->quarantined($analyst, '2099-01-01');
        DB::connection('migrator')->table('evidence_assets')->where('id', $without->id)->update(['created_at' => '2026-03-01 23:59:59']);

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        $this->assertFalse(Schema::hasColumn('evidence_assets', 'retention_until'));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));

        $this->assertSame(['2027-04-05', '2026-03-31'], [$withPolicy->fresh()?->retention_until, $without->fresh()?->retention_until]);
    }

    /** O38: the deletion check holds its row lock until the deleting transaction ends, so it refuses to run outside one. */
    public function test_the_deletion_check_runs_only_inside_the_deleting_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertThrows(fn () => app(EvidenceBlobReconciler::class)->deletable(1, CarbonImmutable::now('UTC')), LogicException::class, 'inside the transaction');
    }

    private function quarantined(User $uploader, string $retentionUntil): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid(),
            'original_filename' => 'copy.pdf', 'size_bytes' => strlen(self::PDF), 'title' => 'Synthetic copy', 'captured_at' => now('UTC'),
            'uploaded_by' => $uploader->id, 'classification' => Classification::Internal, 'retention_until' => $retentionUntil,
        ])->save();
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->put((string) $asset->quarantine_key, self::PDF);

        return $asset;
    }
}
