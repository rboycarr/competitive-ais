<?php

namespace Modules\Evidence\Tests\Feature;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourceLifecycleService;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-37 / docs/04 §2 / docs/02 §3.5: evidence names the source it came from, and records the source's policy version
 * in force at capture, so an auditor can see which policy authorized it. Only a source the uploader can see that is
 * `approved` (with a policy in force) can be named; a later suspension or a new policy never rewrites earlier
 * captures. The database holds that the policy is an approval of the same source.
 */
class EvidenceSourcePolicyTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $analyst;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        Queue::fake();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
    }

    public function test_an_upload_records_the_policy_in_force_and_an_auditor_sees_it(): void
    {
        $source = $this->approved('Trade news');
        $this->actingAs($this->analyst)->get('/evidence')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', [['value' => $source->ulid, 'label' => 'Trade news']]));

        $this->actingAs($this->analyst)->post('/evidence', $this->form(['source' => $source->ulid]))->assertSessionHasNoErrors();

        $asset = EvidenceAsset::query()->sole();
        $this->assertSame([$source->id, $source->current_policy_id], [$asset->source_id, $asset->source_policy_id]);
        $this->assertSame('approved', DB::table('evidence_assets')->where('id', $asset->id)->value('source_policy_decision'));
        $audit = DB::table('audit_events')->where('action', 'evidence.uploaded')->sole();
        $this->assertEquals(['classification' => 'internal', 'source_policy' => $source->current_policy_id], json_decode((string) $audit->context, true));

        $this->promote($asset);
        $auditor = $this->createUser('auditor@example.test', 'auditor', clearance: Classification::Internal);
        $this->actingAs($auditor)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.source.visible', true)
            ->where('asset.source.ulid', $source->ulid)
            ->where('asset.source.name', 'Trade news')
            ->where('asset.source.status', 'approved')
            ->where('asset.source.policyVersion', 1)
            ->where('asset.source.policyDecidedAt', fn (string $at): bool => str_ends_with($at, 'Z')));

        // Evidence without a source says so.
        $this->actingAs($this->analyst)->post('/evidence', $this->form(['title' => 'Unsourced']))->assertSessionHasNoErrors();
        $unsourced = EvidenceAsset::query()->where('title', 'Unsourced')->sole();
        $this->assertSame([null, null], [$unsourced->source_id, $unsourced->source_policy_id]);
        $this->actingAs($this->analyst)->get("/evidence/{$unsourced->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('asset.source', null));
    }

    public function test_a_source_the_viewer_cannot_see_is_shown_as_a_placeholder_without_its_name(): void
    {
        $source = $this->approved('Confidential panel', 'confidential');
        $asset = app(EvidenceUploadService::class)->upload($this->analyst, $this->file(), $this->fields(['source' => $source->ulid]));
        $this->promote($asset);
        $auditor = $this->createUser('auditor@example.test', 'auditor', clearance: Classification::Internal);

        $this->actingAs($auditor)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.source', ['visible' => false]));
        $this->actingAs($auditor)->get("/sources/{$source->ulid}")->assertNotFound();
    }

    public function test_unapproved_unknown_or_hidden_sources_are_refused_and_nothing_is_stored(): void
    {
        $proposed = $this->propose('Proposed');
        $inReview = $this->propose('In review');
        app(SourcePolicyService::class)->submit($this->analyst, $inReview);
        $suspended = $this->approved('Suspended');
        $this->lifecycle($suspended, 'suspend', 'complaint');
        $retired = $this->approved('Retired');
        $this->lifecycle($retired, 'retire', 'no_longer_needed');
        $hidden = $this->approved('Confidential panel', 'confidential');
        // A collector cleared for internal records: the confidential source is approved but hidden from them.
        $collector = $this->createUser('collector@example.test', 'data_collector', clearance: Classification::Internal);

        $this->actingAs($collector)->get('/evidence')->assertInertia(fn (AssertableInertia $page) => $page->where('sources', []));

        $notApproved = 'This source has no approved policy in force, so evidence cannot be captured from it.';
        $cases = [
            'proposed' => [$proposed->ulid, $notApproved],
            'in policy review' => [$inReview->ulid, $notApproved],
            'suspended' => [$suspended->ulid, $notApproved],
            'retired' => [$retired->ulid, $notApproved],
            'above clearance' => [$hidden->ulid, 'Choose a source you can see.'],
            'unknown' => [(string) Str::ulid(), 'Choose a source you can see.'],
            'not a ULID' => ['../../etc/passwd', 'Choose a source you can see.'],
            'not text' => [['x'], 'Choose a source you can see.'],
        ];
        foreach ($cases as $case => [$value, $message]) {
            try {
                app(EvidenceUploadService::class)->upload($collector, $this->file(), $this->fields(['source' => $value]));
                $this->fail("Accepted: $case");
            } catch (ValidationException $e) {
                $this->assertSame([$message], $e->errors()['source'] ?? null, $case);
            }
        }
        $this->actingAs($collector)->post('/evidence', $this->form(['source' => $suspended->ulid]))->assertSessionHasErrors(['source' => $notApproved]);
        $this->actingAs($this->analyst)->get('/evidence')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sources', [['value' => $hidden->ulid, 'label' => 'Confidential panel']])); // only approved sources
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_a_policy_change_applies_to_later_captures_and_never_rewrites_earlier_ones(): void
    {
        $source = $this->approved('Trade news');
        $uploads = app(EvidenceUploadService::class);
        $first = $uploads->upload($this->analyst, $this->file(), $this->fields(['title' => 'Under v1', 'source' => $source->ulid]));
        $v1 = (int) $source->current_policy_id;

        // Suspended, then back in policy review: no capture, whatever the policy on record.
        $this->lifecycle($source, 'suspend', 'terms_changed');
        $this->assertSame($v1, $source->refresh()->current_policy_id);
        $this->assertThrows(fn () => $uploads->upload($this->analyst, $this->file(), $this->fields(['source' => $source->ulid])), ValidationException::class);
        $this->lifecycle($source, 'reinstate', 'resolved');
        $this->assertThrows(fn () => $uploads->upload($this->analyst, $this->file(), $this->fields(['source' => $source->ulid])), ValidationException::class);

        // A new approval (version 2) applies from now on.
        app(SourcePolicyService::class)->decide($this->director, $source->refresh(), $this->decision(['based_on_version' => 1, 'terms_summary' => 'Synthetic terms, revised.']));
        $v2 = (int) $source->refresh()->current_policy_id;
        $this->assertNotSame($v1, $v2);
        $second = $uploads->upload($this->analyst, $this->file(), $this->fields(['title' => 'Under v2', 'source' => $source->ulid]));

        $this->assertSame([$v1, $v2], [$first->fresh()?->source_policy_id, $second->fresh()?->source_policy_id]);
        $this->actingAs($this->analyst)->get("/evidence/{$first->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.source.policyVersion', 1)->where('asset.source.status', 'approved'));
        $this->actingAs($this->analyst)->get("/evidence/{$second->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.source.policyVersion', 2));
    }

    public function test_a_suspension_committed_after_the_first_check_is_honoured_in_the_transaction(): void
    {
        $source = $this->approved('Trade news');
        $suspended = false;
        // The check before the file is stored passes; the source is suspended as the capture's transaction begins, so
        // only the check under the share lock can refuse it.
        Event::listen(TransactionBeginning::class, function () use (&$suspended, $source): void {
            if (! $suspended) {
                $suspended = true;
                DB::table('sources')->where('id', $source->id)->update(['status' => 'suspended']);
            }
        });

        $this->assertThrows(fn () => app(EvidenceUploadService::class)->upload($this->analyst, $this->file(), $this->fields(['source' => $source->ulid])), ValidationException::class);
        $this->assertTrue($suspended, 'the source was suspended inside the transaction');
        Event::forget(TransactionBeginning::class);
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());
    }

    public function test_the_database_holds_the_capture_rules_and_rollback_is_refused_while_any_evidence_names_a_source(): void
    {
        $source = $this->approved('Trade news');
        $other = $this->approved('Other news');
        $rejected = $this->propose('Rejected');
        app(SourcePolicyService::class)->submit($this->analyst, $rejected);
        app(SourcePolicyService::class)->decide($this->director, $rejected, $this->decision(['decision' => 'rejected', 'notes' => 'Synthetic rejection.']));
        $rejectedPolicy = (int) DB::table('source_policies')->where('source_id', $rejected->id)->value('id');
        $asset = app(EvidenceUploadService::class)->upload($this->analyst, $this->file(), $this->fields());
        $set = fn (array $values): int => DB::table('evidence_assets')->where('id', $asset->id)->update($values);

        $this->assertDatabaseError(3819, fn () => $set(['source_id' => $source->id]), 'source without a policy');
        $this->assertDatabaseError(3819, fn () => $set(['source_policy_id' => $source->current_policy_id]), 'policy without a source');
        $this->assertDatabaseError(1452, fn () => $set(['source_id' => $source->id, 'source_policy_id' => $other->current_policy_id]), "another source's policy");
        $this->assertDatabaseError(1452, fn () => $set(['source_id' => $rejected->id, 'source_policy_id' => $rejectedPolicy]), 'a rejection, not an approval');
        $this->assertDatabaseError(1452, fn () => $set(['source_id' => 999999, 'source_policy_id' => 999999]), 'unknown source and policy');
        $this->assertDatabaseError(3105, fn () => $set(['source_policy_decision' => 'rejected']), 'the generated decision');
        $this->assertSame(1, $set(['source_id' => $source->id, 'source_policy_id' => $source->current_policy_id]));

        $migration = require base_path('modules/Evidence/database/migrations/2026_11_22_000100_add_source_policy_to_evidence_assets.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'source policy');
    }

    private function propose(string $name, string $classification = 'internal'): Source
    {
        return app(SourceService::class)->propose($this->analyst, ['name' => $name, 'source_type' => 'website', 'classification' => $classification]);
    }

    private function approved(string $name, string $classification = 'internal'): Source
    {
        $source = $this->propose($name, $classification);
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        app(SourcePolicyService::class)->decide($this->director, $source, $this->decision());

        return $source->refresh();
    }

    private function lifecycle(Source $source, string $action, string $reason): void
    {
        app(SourceLifecycleService::class)->change($this->director, $source->refresh(), $action, $reason, 'Synthetic note.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{decision: string, based_on_version: int, robots_decision: string, contains_personal_data: bool}
     */
    private function decision(array $overrides = []): array
    {
        /** @var array{decision: string, based_on_version: int, robots_decision: string, contains_personal_data: bool} $form */
        $form = $overrides + [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ];

        return $form;
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('a.pdf', self::PDF);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + ['title' => 'Captured', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return ['file' => $this->file()] + $this->fields($overrides);
    }

    /** Makes the asset available as a clean scan would (the scan itself is covered by EvidenceScanTest). */
    private function promote(EvidenceAsset $asset): void
    {
        $blobId = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd', 'first_scanned_at' => now(),
            'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        DB::table('evidence_assets')->where('id', $asset->id)->update([
            'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blobId, 'quarantine_key' => null,
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
