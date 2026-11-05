<?php

namespace Modules\Evidence\Tests\Feature;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Evidence\Actions\EvidenceHoldService;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-35 / ADR-0007 / PO decisions O35, O36: evidence.hold holders place and release a legal hold on available evidence,
 * with a reason; each change is a custody event and an audit event; the blob's hold is the OR of its assets' holds and
 * is pushed to the stored version as an S3 legal hold. The object store is the real S3 adapter with the SDK's mock
 * handler: every request is captured, none reaches a store.
 */
class EvidenceHoldTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    private User $analyst;

    /** @var list<array{string, ?string, ?string, ?string}> name, key, version, status */
    private array $requests = [];

    private bool $storeFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        $requests = &$this->requests;
        $fails = &$this->storeFails;
        $answer = function (CommandInterface $command) use (&$requests, &$fails): Result {
            if ($fails) {
                throw new AwsException('Synthetic store failure.', $command);
            }
            $requests[] = [$command->getName(), $command['Key'] ?? null, $command['VersionId'] ?? null, $command['LegalHold']['Status'] ?? null];

            return new Result([]);
        };
        $disk = Storage::disk(EvidenceScanService::EVIDENCE_DISK);
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler(array_fill(0, 20, $answer)));

        // Directors hold privileged permissions, so they work only with two-factor confirmed (P1-12).
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_a_director_places_a_hold_with_a_reason_and_it_reaches_the_stored_version(): void
    {
        $asset = $this->available();
        $this->actingAs($this->director)->post("/evidence/{$asset->ulid}/hold", ['hold' => true, 'reason' => "  Litigation notice 12\nkeep all copies  "])
            ->assertRedirect("/evidence/{$asset->ulid}")->assertSessionHas('status', 'Placed on legal hold.');

        $blob = EvidenceBlob::query()->sole();
        $this->assertSame([true, true], [$asset->fresh()?->legal_hold, $blob->legal_hold]);
        $this->assertSame([['PutObjectLegalHold', $blob->storage_key, 'synthetic-version', 'ON']], $this->requests);

        $event = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->sole();
        $this->assertSame(['hold_set', 'user', $this->director->id, ['reason' => "Litigation notice 12\nkeep all copies"]], [$event->event, $event->actor_type, $event->actor_id, $event->details]);
        $audit = DB::table('audit_events')->where('action', 'evidence.hold_set')->sole();
        $this->assertSame([$this->director->id, $asset->ulid], [$audit->actor_id, $audit->subject_ulid]);
        $this->assertEquals(['blob_hold' => 'on'], json_decode((string) $audit->context, true));
    }

    public function test_a_shared_blob_stays_held_until_its_last_held_asset_is_released(): void
    {
        $first = $this->available();
        $second = $this->available(blobId: $first->evidence_blob_id);
        $holds = app(EvidenceHoldService::class);

        $holds->change($this->director, $first, true, 'Notice A');
        $holds->change($this->director, $second, true, 'Notice B');
        $holds->change($this->director, $first, false, 'Notice A withdrawn');
        $this->assertTrue(EvidenceBlob::query()->sole()->legal_hold);
        $this->assertSame(['ON'], array_column($this->requests, 3), 'only the first hold changed the blob');

        $holds->change($this->director, $second, false, 'Notice B withdrawn');
        $this->assertFalse(EvidenceBlob::query()->sole()->legal_hold);
        $this->assertSame(['ON', 'OFF'], array_column($this->requests, 3));
        $this->assertSame(['hold_set', 'hold_released'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $first->id)->orderBy('id')->pluck('event')->all());
        $this->assertEquals(['blob_hold' => 'on'], json_decode((string) DB::table('audit_events')->where('action', 'evidence.hold_released')->orderBy('id')->value('context'), true));
    }

    public function test_a_hold_the_store_refuses_is_not_recorded(): void
    {
        $asset = $this->available();
        $this->storeFails = true;

        $this->assertThrows(fn () => app(EvidenceHoldService::class)->change($this->director, $asset, true, 'Notice'), AwsException::class);
        $this->assertSame([false, false], [$asset->fresh()?->legal_hold, EvidenceBlob::query()->sole()->legal_hold]);
        $this->assertNothingRecorded($asset);
    }

    public function test_a_release_the_store_refuses_leaves_the_object_held_and_is_logged(): void
    {
        $asset = $this->available();
        app(EvidenceHoldService::class)->change($this->director, $asset, true, 'Notice');
        $this->storeFails = true;
        $log = Log::spy();

        app(EvidenceHoldService::class)->change($this->director, $asset, false, 'Notice withdrawn');
        $this->assertSame([false, false], [$asset->fresh()?->legal_hold, EvidenceBlob::query()->sole()->legal_hold]);
        $this->assertSame(['ON'], array_column($this->requests, 3), 'the object was never released');
        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'still held') && $context === ['evidence_blob_id' => $asset->evidence_blob_id]);
    }

    public function test_only_evidence_hold_holders_who_see_the_asset_can_change_a_hold(): void
    {
        $asset = $this->available();
        $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/hold", ['hold' => true, 'reason' => 'Notice'])->assertForbidden();
        $this->assertThrows(fn () => app(EvidenceHoldService::class)->change($this->analyst, $asset, true, 'Notice'), AuthorizationException::class);

        $hidden = $this->available(classification: Classification::Restricted);
        $this->actingAs($this->director)->post("/evidence/{$hidden->ulid}/hold", ['hold' => true, 'reason' => 'Notice'])->assertNotFound();
        $this->actingAs($this->director)->get("/evidence/{$asset->ulid}/hold")->assertMethodNotAllowed();
        $this->assertNothingRecorded($asset);
        $this->assertNothingRecorded($hidden);
    }

    public function test_a_reason_is_required_and_only_available_evidence_can_be_held(): void
    {
        $asset = $this->available();
        $holds = app(EvidenceHoldService::class);
        foreach (['', '   ', "bad \xff text", "bell \x07", str_repeat('x', 1001)] as $reason) {
            $this->assertThrows(fn () => $holds->change($this->director, $asset, true, $reason), ValidationException::class);
        }
        $this->actingAs($this->director)->post("/evidence/{$asset->ulid}/hold", ['hold' => true])->assertSessionHasErrors('reason');
        $this->assertThrows(fn () => $holds->change($this->director, $asset, false, 'Not held'), ValidationException::class, 'not on legal hold');

        $quarantined = $this->quarantined();
        $this->actingAs($this->director)->from("/evidence/{$quarantined->ulid}")->post("/evidence/{$quarantined->ulid}/hold", ['hold' => true, 'reason' => 'Notice'])
            ->assertRedirect("/evidence/{$quarantined->ulid}")->assertSessionHasErrors('hold');
        $this->assertNothingRecorded($asset);
        $this->assertNothingRecorded($quarantined);

        $holds->change($this->director, $asset, true, 'Notice');
        $this->assertThrows(fn () => $holds->change($this->director, $asset, true, 'Again'), ValidationException::class, 'already on legal hold');
        $this->assertSame(1, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->count());
    }

    public function test_the_database_holds_the_hold_rule_and_rollback_is_refused_while_held(): void
    {
        $quarantined = $this->quarantined();
        $this->assertDatabaseError(3819, fn () => DB::table('evidence_assets')->where('id', $quarantined->id)->update(['legal_hold' => true]), 'hold on quarantined evidence');

        $asset = $this->available();
        app(EvidenceHoldService::class)->change($this->director, $asset, true, 'Notice');
        // A held asset cannot leave `available` without its hold being released first.
        $this->assertDatabaseError(3819, fn () => DB::table('evidence_assets')->where('id', $asset->id)
            ->update(['status' => 'rejected', 'evidence_blob_id' => null]), 'held asset leaving available');

        $migration = require base_path('modules/Evidence/database/migrations/2026_11_20_000100_add_legal_hold_to_evidence_assets.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'legal hold');
        $this->assertTrue((bool) $asset->fresh()?->legal_hold);
    }

    public function test_the_detail_page_shows_the_hold_and_its_reasons_and_offers_it_only_to_holders(): void
    {
        $asset = $this->available();
        app(EvidenceHoldService::class)->change($this->director, $asset, true, 'Litigation notice 12');

        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.legalHold', true)->where('canHold', false)
            ->where('custody.0.event', 'hold_set')->where('custody.0.reason', 'Litigation notice 12'));
        $this->actingAs($this->director)->get("/evidence/{$asset->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canHold', true));
        $this->actingAs($this->director)->get("/evidence/{$this->quarantined()->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canHold', false));
    }

    private function assertNothingRecorded(EvidenceAsset $asset): void
    {
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->whereIn('event', ['hold_set', 'hold_released'])->count());
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['evidence.hold_set', 'evidence.hold_released'])->where('subject_ulid', $asset->ulid)->count());
        $this->assertFalse((bool) $asset->fresh()?->legal_hold);
    }

    private function available(?int $blobId = null, Classification $classification = Classification::Internal): EvidenceAsset
    {
        $blobId ??= (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);

        return $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blobId, 'classification' => $classification]);
    }

    private function quarantined(): EvidenceAsset
    {
        return $this->asset(['status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill($attributes + [
            'ulid' => (string) Str::ulid(), 'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic hold',
            'captured_at' => now(), 'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal,
        ])->save();

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
