<?php

namespace Modules\Evidence\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Jobs\ScanEvidenceJob;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-32a / ADR-0007 / docs/07 T7: evidence.upload holders upload into the quarantine bucket under a generated key;
 * the type is detected from the bytes and must match the extension; the file name is shown, never used as a key; the
 * asset, its custody event and the audit event are written together, and the object is removed if they fail. Until an
 * asset is available, only its uploader and evidence.hold holders see it (PO decision O32); the database holds the
 * status rules. Synthetic files only.
 */
class EvidenceUploadTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0dIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    /** The start of a Windows executable (synthetic: a DOS header, no program). */
    private const EXE = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00\xb8\x00\x00\x00\x00\x00\x00\x00@\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x80\x00\x00\x00PE\x00\x00L\x01";

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Queue::fake();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_an_analyst_uploads_into_quarantine_and_it_is_recorded(): void
    {
        $response = $this->actingAs($this->analyst)->post('/evidence', $this->form('report.pdf', self::PDF, [
            'title' => '  Shelf   price list  ', 'description' => 'Synthetic test entry.', 'source_url' => 'https://shop.example/item?id=7',
            'captured_at' => '2026-11-02T08:30:00Z', 'classification' => 'confidential',
        ]))->assertSessionHasNoErrors();

        $asset = EvidenceAsset::query()->sole();
        $response->assertRedirect("/evidence/{$asset->ulid}")->assertSessionHas('status', 'Shelf price list uploaded. It stays in quarantine until it has been scanned.');
        $this->assertSame(
            ['quarantined', 'pending', null, 'quarantine/'.$asset->ulid, 'report.pdf', strlen(self::PDF), 'Shelf price list', 'Synthetic test entry.', 'https://shop.example/item?id=7', '2026-11-02 08:30:00', $this->analyst->id, Classification::Confidential],
            [$asset->status, $asset->scan_status, $asset->scanned_at, $asset->quarantine_key, $asset->original_filename, $asset->size_bytes, $asset->title, $asset->description, $asset->source_url,
                $asset->captured_at->format('Y-m-d H:i:s'), $asset->uploaded_by, $asset->classification],
        );
        // The bytes, unchanged, under the generated key only.
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists('quarantine/'.$asset->ulid);
        $this->assertSame(self::PDF, Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->get('quarantine/'.$asset->ulid));
        $this->assertSame(['quarantine/'.$asset->ulid], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
        Queue::assertPushed(ScanEvidenceJob::class, fn (ScanEvidenceJob $job): bool => $job->assetId === $asset->id && $job->queue === 'evidence');

        $custody = EvidenceCustodyEvent::query()->sole();
        $this->assertSame([$asset->id, 'uploaded', 'user', $this->analyst->id, null], [$custody->evidence_asset_id, $custody->event, $custody->actor_type, $custody->actor_id, $custody->details]);
        $audit = DB::table('audit_events')->where('action', 'evidence.uploaded')->sole();
        $this->assertSame([$asset->ulid, 'confidential', (int) $this->analyst->id], [$audit->subject_ulid, $audit->classification, (int) $audit->actor_id]);
        $this->assertEquals(['classification' => 'confidential'], json_decode((string) $audit->context, true));

        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Evidence/Show')->where('asset.status', 'quarantined')->where('asset.scanStatus', 'pending')
            ->where('asset.originalFilename', 'report.pdf')->where('custody.0.event', 'uploaded')->where('custody.0.actor', 'Test analyst@example.test'));
    }

    public function test_reading_needs_evidence_view_and_uploading_needs_evidence_upload(): void
    {
        $auditor = $this->createUser('auditor@example.test', 'auditor'); // evidence.view, no upload
        $reader = $this->createUser('reader@example.test', 'exec_reader'); // neither
        $collector = $this->createUser('collector@example.test', 'data_collector'); // view and upload

        $this->actingAs($auditor)->get('/evidence')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canUpload', false)->where('classifications', []));
        $this->actingAs($auditor)->post('/evidence', $this->form('a.pdf', self::PDF))->assertForbidden();
        $this->actingAs($reader)->get('/evidence')->assertForbidden();
        $this->actingAs($reader)->post('/evidence', $this->form('a.pdf', self::PDF))->assertForbidden();
        $this->actingAs($collector)->post('/evidence', $this->form('a.pdf', self::PDF))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->delete('/evidence/'.EvidenceAsset::query()->sole()->ulid)->assertStatus(405);
        $this->assertSame(1, EvidenceAsset::query()->count());

        // The service checks the actor itself.
        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($auditor, $this->file('a.pdf', self::PDF), $this->fields()), AuthorizationException::class);
        $this->assertSame(1, EvidenceAsset::query()->count());
    }

    public function test_the_type_comes_from_the_bytes_and_must_match_the_extension(): void
    {
        foreach ([
            'an executable named .pdf' => ['invoice.pdf', self::EXE],
            'a PDF named .png' => ['photo.png', self::PDF],
            'a PNG named .pdf' => ['scan.pdf', self::PNG],
            'an HTML page named .txt' => ['notes.txt', "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n"],
            'no extension' => ['report', self::PDF],
            'an SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ] as $case => [$name, $bytes]) {
            $this->actingAs($this->analyst)->post('/evidence', $this->form($name, $bytes))->assertSessionHasErrors('file', $case);
        }
        foreach ([['photo.PNG', self::PNG], ['notes.txt', "Synthetic note.\n"], ['prices.csv', "sku,price\nA1,10.00\n"]] as [$name, $bytes]) {
            $this->actingAs($this->analyst)->post('/evidence', $this->form($name, $bytes))->assertSessionHasNoErrors();
        }
        $this->assertSame(['photo.PNG', 'notes.txt', 'prices.csv'], EvidenceAsset::query()->orderBy('id')->pluck('original_filename')->all());
        $this->assertCount(3, Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_size_and_fields_are_validated(): void
    {
        config(['evidence.max_bytes' => 2048]); // 2 KB: the form request's rule counts kilobytes
        $tomorrow = now('UTC')->addDay()->toIso8601ZuluString();
        foreach ([
            'file' => $this->form('big.txt', str_repeat('x', 2049)),
            'title' => $this->form('a.pdf', self::PDF, ['title' => '   ']),
            'captured_at' => $this->form('a.pdf', self::PDF, ['captured_at' => $tomorrow]),
            'source_url' => $this->form('a.pdf', self::PDF, ['source_url' => 'javascript:alert(1)']),
            'classification' => $this->form('a.pdf', self::PDF, ['classification' => 'secret']),
        ] as $field => $form) {
            $this->actingAs($this->analyst)->post('/evidence', $form)->assertSessionHasErrors($field);
        }
        // The service holds the rules without the form request.
        foreach ([
            ['file', $this->file('empty.txt', ''), []],
            ['file', $this->file('big.txt', str_repeat('x', 2049)), []], // over the limit, without the form request
            ['file', $this->file('invoice.pdf', self::EXE), []], // the bytes decide, not the name
            ['source_url', $this->file('a.pdf', self::PDF), ['source_url' => 'https://user:pw@shop.example/']],
            ['captured_at', $this->file('a.pdf', self::PDF), ['captured_at' => 'yesterday-ish']],
            ['classification', $this->file('a.pdf', self::PDF), ['classification' => 'restricted']], // above the analyst's clearance
            ['title', $this->file('a.pdf', self::PDF), ['title' => ['array']]],
        ] as [$field, $file, $override]) {
            try {
                app(EvidenceUploadService::class)->upload($this->analyst, $file, $override + $this->fields());
                $this->fail("Expected a validation error for $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles(), 'nothing stored for a refused upload');
    }

    public function test_file_names_are_cleaned_for_display_and_never_used_as_keys(): void
    {
        $this->assertSame('passwd.txt', EvidenceUploadService::displayName('../../etc/passwd.txt'));
        $this->assertSame('evil.txt', EvidenceUploadService::displayName('C:\\Users\\x\\evil.txt'));
        $this->assertSame('invoicefdp.exe.txt', EvidenceUploadService::displayName("invoice\u{202E}fdp.exe.txt")); // right-to-left override removed
        $this->assertSame('a b.txt', EvidenceUploadService::displayName("a\t\n b\x00.txt"));
        $this->assertSame('café.txt', EvidenceUploadService::displayName("cafe\u{0301}.txt")); // NFC
        $this->assertSame('upload', EvidenceUploadService::displayName("../\x01"));
        $long = EvidenceUploadService::displayName(str_repeat('é', 300).'.pdf');
        $this->assertSame([255, '.pdf'], [mb_strlen($long), mb_substr($long, -4)]);

        $this->actingAs($this->analyst)->post('/evidence', $this->form("../../x\u{202E}gnp.pdf", self::PDF))->assertSessionHasNoErrors();
        $asset = EvidenceAsset::query()->sole();
        $this->assertSame(['xgnp.pdf', ['quarantine/'.$asset->ulid]], [$asset->original_filename, Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles()]);
    }

    public function test_the_object_is_removed_when_recording_the_upload_fails(): void
    {
        EvidenceAsset::saved(fn () => throw new RuntimeException('Synthetic database failure.'));

        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file('a.pdf', self::PDF), $this->fields()), RuntimeException::class, 'Synthetic database failure.');
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame(0, EvidenceCustodyEvent::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_a_write_that_fails_part_way_leaves_nothing_behind(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('Synthetic write failure.'));
        $disk->shouldReceive('delete')->once()->with(Mockery::pattern('#^quarantine/[0-9A-HJKMNP-TV-Z]{26}$#'))->andReturn(true);
        Storage::set(EvidenceUploadService::QUARANTINE_DISK, $disk);

        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file('a.pdf', self::PDF), $this->fields()), RuntimeException::class, 'Synthetic write failure.');
        $this->assertSame([0, 0], [EvidenceAsset::query()->count(), EvidenceCustodyEvent::query()->count()]);
    }

    public function test_an_object_that_cannot_be_removed_is_logged_by_its_key_only(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('Synthetic delete failure.'));
        Storage::set(EvidenceUploadService::QUARANTINE_DISK, $disk);
        EvidenceAsset::saved(fn () => throw new RuntimeException('Synthetic database failure.'));
        $log = Log::spy();

        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file('secret-plans.pdf', self::PDF), ['title' => 'Do not log me'] + $this->fields()), RuntimeException::class, 'Synthetic database failure.');
        $log->shouldHaveReceived('warning')->once()->with('evidence.quarantine_orphan', Mockery::on(fn (array $context): bool => array_keys($context) === ['quarantine_key', 'cleanup_error']
            && preg_match('#^quarantine/[0-9A-Z]{26}$#', $context['quarantine_key']) === 1 && $context['cleanup_error'] === RuntimeException::class));
        $this->assertSame(0, EvidenceAsset::query()->count());
    }

    public function test_a_delete_that_returns_false_is_logged_as_an_orphan_too(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andThrow(new RuntimeException('Synthetic write failure.'));
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::set(EvidenceUploadService::QUARANTINE_DISK, $disk);
        $log = Log::spy();

        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file('a.pdf', self::PDF), $this->fields()), RuntimeException::class, 'Synthetic write failure.');
        $log->shouldHaveReceived('warning')->once()->with('evidence.quarantine_orphan', Mockery::on(fn (array $context): bool => preg_match('#^quarantine/[0-9A-Z]{26}$#', $context['quarantine_key']) === 1
            && $context['cleanup_error'] === RuntimeException::class));
        $this->assertSame([0, 0], [EvidenceAsset::query()->count(), EvidenceCustodyEvent::query()->count()]); // TASK-0066
    }

    public function test_a_failed_scan_dispatch_does_not_undo_an_already_committed_upload(): void
    {
        // The asset is committed before the job is ever dispatched (P1-32b rework): a queue outage must not delete an
        // upload that already succeeded. app(Dispatcher::class) is what Job::dispatch()'s PendingDispatch calls.
        app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic queue failure.'));
        }));

        $this->actingAs($this->analyst)->post('/evidence', $this->form('report.pdf', self::PDF))->assertSessionHasNoErrors();

        $asset = EvidenceAsset::query()->sole();
        $this->assertSame('quarantined', $asset->status);
        Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->assertExists((string) $asset->quarantine_key);
        $this->assertSame(1, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
    }

    public function test_malformed_text_suspended_users_and_disguised_archives_are_refused(): void
    {
        foreach (['title', 'description', 'source_url'] as $field) {
            try {
                app(EvidenceUploadService::class)->upload($this->analyst, $this->file('a.pdf', self::PDF), [$field => "bad \xff text"] + $this->fields());
                $this->fail("Expected a validation error for malformed $field.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        // A malformed file name cleans to "upload", which has no accepted extension.
        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file("report\xff.pdf", self::PDF), $this->fields()), ValidationException::class);
        // A plain ZIP archive is not a Word document, whatever its name.
        $this->actingAs($this->analyst)->post('/evidence', $this->form('report.docx', "PK\x03\x04\x14\x00\x00\x00\x00\x00".str_repeat("\x00", 20)))->assertSessionHasErrors('file');

        $suspended = $this->createUser('suspended@example.test', 'ci_analyst', 'suspended', Classification::Confidential);
        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($suspended, $this->file('a.pdf', self::PDF), $this->fields()), AuthorizationException::class);
        $this->assertSame([0, []], [EvidenceAsset::query()->count(), Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles()]);
    }

    public function test_until_available_only_the_uploader_and_holders_see_an_asset(): void
    {
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $lowDirector = $this->enableTwoFactor($this->createUser('low-director@example.test', 'ci_director')); // internal clearance
        $quarantined = $this->asset('In quarantine', 'quarantined', 'pending', Classification::Confidential);
        $rejected = $this->asset('Rejected', 'rejected', 'infected', Classification::Internal);
        $available = $this->asset('Available', 'available', 'clean', Classification::Internal);
        $withdrawn = $this->asset('Withdrawn', 'withdrawn', 'clean', Classification::Internal);

        $sees = fn (User $user): array => array_column($this->actingAs($user)->get('/evidence')->inertiaProps('assets'), 'title');
        $this->assertEqualsCanonicalizing(['In quarantine', 'Rejected', 'Available', 'Withdrawn'], $sees($this->analyst)); // the uploader
        $this->assertEqualsCanonicalizing(['Available', 'Withdrawn'], $sees($other));
        $this->assertEqualsCanonicalizing(['In quarantine', 'Rejected', 'Available', 'Withdrawn'], $sees($director)); // evidence.hold
        $this->assertEqualsCanonicalizing(['Rejected', 'Available', 'Withdrawn'], $sees($lowDirector)); // clearance still applies

        foreach ([[$other, $quarantined, 404], [$other, $rejected, 404], [$other, $available, 200], [$director, $quarantined, 200], [$lowDirector, $quarantined, 404], [$this->analyst, $rejected, 200]] as [$user, $asset, $status]) {
            $this->actingAs($user)->get("/evidence/{$asset->ulid}")->assertStatus($status);
        }
        $this->actingAs($other)->get('/evidence?status=quarantined')->assertInertia(fn (AssertableInertia $page) => $page->where('assets', []));
        $this->actingAs($other)->get('/evidence?status=gone')->assertSessionHasErrors('status');
        $this->assertSame('Withdrawn', $withdrawn->title);
    }

    public function test_visibility_of_available_evidence_follows_the_matrix(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: evidence.view
        $uploader = $this->createUser('uploader@example.test', 'data_collector', clearance: Classification::Restricted);
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] === [] && $r['markets'] === []));
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->asset('Evidence '.$record['label'], 'available', 'clean', Classification::from($record['classification']), $uploader)->ulid;
        }

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = EvidenceAsset::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'EvidenceAsset::visibleTo()');

        $active = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && in_array($u['grant'], ['none', 'all:restricted'], true)));
        AuthorizationMatrix::assertMatrix($active, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->get("/evidence/{$ulids[$record['label']]}")->getStatusCode();
            $this->assertContains($status, [200, 404]);

            return $status === 200;
        }, 'GET /evidence/{ulid}');
        $shown = [];
        foreach ($active as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/evidence')->inertiaProps('assets'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($active, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /evidence');
    }

    public function test_the_database_holds_the_evidence_rules(): void
    {
        $row = fn (array $overrides): array => $overrides + [
            'ulid' => (string) Str::ulid(), 'status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid(),
            'original_filename' => 'a.pdf', 'size_bytes' => 10, 'title' => 'Probe', 'captured_at' => now(), 'uploaded_by' => $this->analyst->id,
            'classification' => 'internal', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([
            ['status' => 'deleted'], ['scan_status' => 'maybe'], ['classification' => 'secret'], ['title' => ' '], ['size_bytes' => 0],
            ['original_filename' => 'a/b.pdf'], ['original_filename' => 'a\\b.pdf'], ['original_filename' => "a\x01.pdf"], ['original_filename' => ' '],
            ['source_url' => 'javascript:alert(1)'], ['source_url' => 'https://user@shop.example/'],
            ['quarantine_key' => null], ['scan_status' => 'clean', 'scanned_at' => now()], ['status' => 'available', 'quarantine_key' => null],
            ['status' => 'rejected', 'quarantine_key' => null], ['scan_status' => 'error'], ['scanned_at' => now()],
            ['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now()], // still holding its quarantine object
            ['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'quarantine_key' => null], // no blob (evidence_assets_blob_check, P1-32b)
        ] as $overrides) {
            $this->assertDatabaseError(3819, fn () => DB::table('evidence_assets')->insert($row($overrides)), (string) json_encode($overrides));
        }
        $id = DB::table('evidence_assets')->insertGetId($row([]));
        DB::table('evidence_assets')->insert($row(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'quarantine_key' => null, 'evidence_blob_id' => $this->blob()]));
        DB::table('evidence_assets')->insert($row(['scan_status' => 'error', 'scanned_at' => now()])); // a failed scan, to be retried

        $event = fn (array $overrides): array => $overrides + ['evidence_asset_id' => $id, 'event' => 'uploaded', 'actor_type' => 'user', 'actor_id' => $this->analyst->id, 'occurred_at' => now()];
        foreach ([['event' => 'deleted'], ['actor_type' => 'system'], ['actor_type' => 'user', 'actor_id' => null], ['actor_type' => 'robot']] as $overrides) {
            $this->assertDatabaseError(3819, fn () => DB::table('evidence_custody_events')->insert($event($overrides)), (string) json_encode($overrides));
        }
        $eventId = DB::table('evidence_custody_events')->insertGetId($event([]));

        // Assets are never deleted; custody is append-only.
        $this->assertDatabaseError(1142, fn () => DB::table('evidence_assets')->where('id', $id)->delete(), 'asset delete');
        $this->assertDatabaseError(1142, fn () => DB::table('evidence_custody_events')->where('id', $eventId)->update(['event' => 'viewed']), 'custody update');
        $this->assertDatabaseError(1142, fn () => DB::table('evidence_custody_events')->where('id', $eventId)->delete(), 'custody delete');
    }

    public function test_rollback_is_refused_while_assets_exist(): void
    {
        $this->asset('Kept', 'quarantined', 'pending', Classification::Internal);

        $migration = require base_path('modules/Evidence/database/migrations/2026_11_18_000100_create_evidence_assets_table.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'Evidence assets exist');
        $this->assertSame(1, EvidenceAsset::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(string $name, string $bytes, array $overrides = []): array
    {
        return ['file' => $this->file($name, $bytes)] + $overrides + $this->fields();
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        return ['title' => 'Synthetic evidence', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal'];
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function asset(string $title, string $status, string $scan, Classification $classification, ?User $uploader = null): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => $status, 'scan_status' => $scan, 'scanned_at' => $scan === 'pending' ? null : now(),
            'quarantine_key' => $status === 'quarantined' ? 'quarantine/'.Str::ulid() : null, 'original_filename' => 'a.pdf', 'size_bytes' => 10,
            'title' => $title, 'captured_at' => now(), 'uploaded_by' => ($uploader ?? $this->analyst)->id, 'classification' => $classification,
            // An available asset always has its blob (evidence_assets_blob_check, P1-32b).
            'evidence_blob_id' => $status === 'available' ? $this->blob() : null,
        ])->save();

        return $asset;
    }

    /** A blob row for fixtures that need one; its content is never actually stored. */
    private function blob(): int
    {
        return (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => 'evidence', 'storage_key' => 'sha256/'.Str::random(60),
            'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd', 'first_scanned_at' => now(),
            'integrity_status' => 'ok', 'created_at' => now(),
        ]);
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
