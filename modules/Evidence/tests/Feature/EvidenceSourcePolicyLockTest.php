<?php

namespace Modules\Evidence\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-37 (TASK-0077 #1, #3), with committed data and separate processes (Concurrently):
 * - a capture racing a suspension reads the source's status under its share lock, after the suspension commits,
 *   and is refused, instead of recording the policy its earlier, non-locking read saw;
 * - the migration rolls back on a database where no evidence names a source, and applies again.
 * DatabaseMigrations, so tearDown removes the governance rows (the app account cannot, and the migrations refuse to
 * roll back while they exist).
 */
class EvidenceSourcePolicyLockTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const MIGRATION = 'modules/Evidence/database/migrations/2026_11_22_000100_add_source_policy_to_evidence_assets.php';

    protected function tearDown(): void
    {
        $migrator = DB::connection('migrator');
        $migrator->table('sources')->update(['current_policy_id' => null, 'status' => 'proposed']);
        $migrator->table('source_policies')->delete();
        $migrator->table('sources')->delete();
        DB::disconnect('migrator');

        parent::tearDown();
    }

    public function test_a_capture_racing_a_suspension_reads_the_status_after_the_suspension_commits(): void
    {
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        Queue::fake();
        [$analyst, $source] = $this->approvedSource();

        // The suspension holds the source row for update (as SourceLifecycleService does) and commits after 3 s; the
        // capture starts 1 s in, so its first check still sees `approved`. Only the share lock in the transaction makes
        // it read the status again after the suspension commits: without it the asset insert would wait on the
        // foreign key's own lock and then record the policy of a source that was suspended before the capture
        // committed (TASK-0077 #3).
        $results = Concurrently::run([
            function () use ($source): void {
                DB::transaction(function () use ($source): void {
                    DB::table('sources')->where('id', $source->id)->lockForUpdate()->first();
                    DB::table('sources')->where('id', $source->id)->update(['status' => 'suspended']);
                    usleep(3_000_000);
                });
            },
            function () use ($analyst, $source): void {
                usleep(1_000_000);
                app(EvidenceUploadService::class)->upload($analyst, $this->file(), $this->fields($source));
            },
        ]);

        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('suspended', DB::table('sources')->where('id', $source->id)->value('status'));
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_the_migration_rolls_back_and_applies_again_when_no_evidence_names_a_source(): void
    {
        $columns = fn (): array => array_column(array_map(fn (object $c): array => (array) $c, DB::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evidence_assets'
             AND COLUMN_NAME LIKE 'source\\_%' AND COLUMN_NAME <> 'source_url' ORDER BY COLUMN_NAME")), 'COLUMN_NAME');
        $constraints = fn (): array => array_column(array_map(fn (object $c): array => (array) $c, DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evidence_assets'
             AND CONSTRAINT_NAME LIKE 'evidence\\_assets\\_source%' AND CONSTRAINT_NAME <> 'evidence_assets_source_url_check' ORDER BY CONSTRAINT_NAME")), 'CONSTRAINT_NAME');
        $applied = [['source_id', 'source_policy_decision', 'source_policy_id'],
            ['evidence_assets_source_id_foreign', 'evidence_assets_source_policy_check', 'evidence_assets_source_policy_foreign']];
        $this->assertSame($applied, [$columns(), $constraints()]);

        // Through the migrator wiring, as a deployment would roll back this one migration.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]));
        $this->assertSame([[], []], [$columns(), $constraints()]);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]));
        $this->assertSame($applied, [$columns(), $constraints()]);
    }

    /** @return array{User, Source} */
    private function approvedSource(): array
    {
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $source = app(SourceService::class)->propose($analyst, ['name' => 'Trade news', 'source_type' => 'website', 'classification' => 'internal']);
        app(SourcePolicyService::class)->submit($analyst, $source);
        app(SourcePolicyService::class)->decide($director, $source, [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ]);

        return [$analyst, $source->refresh()];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('a.pdf', self::PDF);
    }

    /** @return array<string, mixed> */
    private function fields(Source $source): array
    {
        return ['title' => 'Captured', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal', 'source' => $source->ulid];
    }
}
