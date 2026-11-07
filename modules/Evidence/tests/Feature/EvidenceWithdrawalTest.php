<?php

namespace Modules\Evidence\Tests\Feature;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Evidence\Actions\EvidenceHoldService;
use Modules\Evidence\Actions\EvidenceIntegrityService;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceWithdrawalService;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceBlob;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Storage\LockedObjects;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-48 / docs/03 Evidence ("Evidence state after publication"; PO decision 2026-11-07): a director withdraws available
 * evidence with a reason (final; kept for provenance, never served or cited again), and the daily re-hash marks stored
 * bytes that no longer match their SHA-256, or are missing, as `mismatch` for good. Each writes custody, audit and an
 * outbox event in one transaction, for Intelligence to flag the items citing the asset. The store is a fake disk except
 * where the S3 adapter is driven through the SDK's mock handler (no request reaches a store).
 */
class EvidenceWithdrawalTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_a_director_withdraws_evidence_with_a_reason(): void
    {
        $asset = $this->available();
        $sha = EvidenceBlob::query()->sole()->sha256;
        $this->actingAs($this->director)->get("/evidence/{$asset->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canWithdraw', true));

        $this->actingAs($this->director)->post("/evidence/{$asset->ulid}/withdraw", ['reason' => "  Fabricated screenshot\nsee ticket 7  "])
            ->assertRedirect("/evidence/{$asset->ulid}")->assertSessionHas('status');

        $asset->refresh();
        $this->assertSame(['withdrawn', null], [$asset->status, $asset->evidence_blob_id]);
        $this->assertSame(1, EvidenceBlob::query()->count(), 'the blob is kept');
        $event = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->sole();
        $this->assertSame(['withdrawn', 'user', $this->director->id, ['reason' => "Fabricated screenshot\nsee ticket 7", 'sha256' => $sha]],
            [$event->event, $event->actor_type, $event->actor_id, $event->details]);
        $audit = DB::table('audit_events')->where('action', 'evidence.withdrawn')->sole();
        $this->assertSame([$this->director->id, $asset->ulid], [$audit->actor_id, $audit->subject_ulid]);
        $this->assertEquals(['sha256' => $sha], json_decode((string) $audit->context, true), 'never the reason');
        $outbox = DB::table('outbox_events')->sole();
        $this->assertSame(['evidence.withdrawn', $asset->id], [$outbox->event_type, (int) $outbox->aggregate_id]);
        $this->assertEquals(['evidence_ulid' => $asset->ulid], json_decode((string) $outbox->payload, true));

        // Still readable for provenance, with the hash it had; never downloadable, held, withdrawn again or cited.
        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.status', 'withdrawn')->where('asset.sha256', $sha)->where('asset.withdrawnAt', $event->occurred_at->toIso8601ZuluString())
            ->where('canDownload', false)->where('canWithdraw', false)->where('custody.0.reason', "Fabricated screenshot\nsee ticket 7"));
        $this->actingAs($this->director)->get("/evidence/{$asset->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canHold', false)->where('canWithdraw', false));
        $this->expectValidation(fn () => $this->withdrawals()->withdraw($this->director, $asset, 'Again'), 'withdraw', 'Only available evidence can be withdrawn.');
        $linking = app(EvidenceLinking::class);
        $this->assertSame([], $linking->available([$asset->id]));
        $this->assertSame([], $linking->linkable($this->director, null, 10));
    }

    public function test_only_directors_withdraw_evidence_they_see_with_a_reason(): void
    {
        $asset = $this->available();
        $this->actingAs($this->analyst)->post("/evidence/{$asset->ulid}/withdraw", ['reason' => 'No'])->assertForbidden();
        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canWithdraw', false));

        // Hidden from the director (above their clearance): not found, whatever the input.
        $secret = $this->available(classification: Classification::Restricted);
        $this->actingAs($this->director)->post("/evidence/{$secret->ulid}/withdraw", [])->assertNotFound();

        foreach ([null, '', "  \n ", ['array'], str_repeat('x', EvidenceHoldService::MAX_REASON + 1), "Bad \x07 bell", "\xB1\x31"] as $reason) {
            $this->expectValidation(fn () => $this->withdrawals()->withdraw($this->director, $asset, $reason), 'reason');
        }
        $this->assertSame(['available', 'available'], [$asset->fresh()?->status, $secret->fresh()?->status]);
        $this->assertNothingRecorded();
    }

    public function test_quarantined_and_held_evidence_is_not_withdrawn(): void
    {
        $quarantined = $this->asset(['status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid()]);
        $this->expectValidation(fn () => $this->withdrawals()->withdraw($this->director, $quarantined, 'Reason'), 'withdraw', 'Only available evidence can be withdrawn.');

        $held = $this->available();
        DB::table('evidence_assets')->where('id', $held->id)->update(['legal_hold' => true]);
        $this->actingAs($this->director)->get("/evidence/{$held->ulid}")->assertInertia(fn (AssertableInertia $page) => $page->where('canWithdraw', false));
        $this->expectValidation(fn () => $this->withdrawals()->withdraw($this->director, $held->refresh(), 'Reason'), 'withdraw',
            'This evidence is on legal hold. Release the hold before withdrawing it.');
        $this->assertSame('available', $held->fresh()?->status);
        $this->assertNothingRecorded();
    }

    public function test_the_re_hash_passes_matching_bytes_and_marks_changed_ones_for_good(): void
    {
        $good = $this->stored('Synthetic report, kept intact.');
        $bad = $this->stored('Synthetic report, as captured.');
        $other = $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $bad->evidence_blob_id]);
        $blob = EvidenceBlob::query()->findOrFail($bad->evidence_blob_id);
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->put($blob->storage_key, 'Synthetic report, altered.');

        $this->assertSame(1, Artisan::call('evidence:verify-integrity'));

        $this->assertSame(['ok', 'mismatch'], [EvidenceBlob::query()->findOrFail($good->evidence_blob_id)->integrity_status, $blob->refresh()->integrity_status]);
        $this->assertNotNull(EvidenceBlob::query()->findOrFail($good->evidence_blob_id)->last_verified_at);
        $this->assertSame(0, EvidenceCustodyEvent::query()->where('evidence_asset_id', $good->id)->count(), 'a passed check records nothing on the asset');
        foreach ([$bad, $other] as $asset) {
            $event = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->sole();
            $this->assertSame(['integrity_failed', 'system', null, ['result' => 'mismatch', 'sha256' => $blob->sha256]], [$event->event, $event->actor_type, $event->actor_id, $event->details]);
        }
        $audits = DB::table('audit_events')->where('action', 'evidence.integrity_failed')->orderBy('id')->get();
        $this->assertSame([$bad->ulid, $other->ulid], $audits->pluck('subject_ulid')->all());
        $first = DB::table('audit_events')->where('action', 'evidence.integrity_failed')->where('subject_ulid', $bad->ulid)->sole();
        $this->assertSame(['system', null], [$first->actor_type, $first->actor_id]);
        $this->assertEquals(['result' => 'mismatch', 'sha256' => $blob->sha256], json_decode((string) $first->context, true));
        $this->assertEquals([['evidence.integrity_failed', $bad->id], ['evidence.integrity_failed', $other->id]],
            DB::table('outbox_events')->orderBy('id')->get()->map(fn ($e): array => [$e->event_type, (int) $e->aggregate_id])->all());

        // No longer support, citable or downloadable; the page says why.
        $linking = app(EvidenceLinking::class);
        $this->assertSame([$good->id], $linking->available([$good->id, $bad->id, $other->id]));
        $this->assertSame([$good->ulid], array_map(fn ($s) => $s->ulid, $linking->linkable($this->director, null, 10)));
        $this->assertNull(DB::transaction(fn () => $linking->lockLinkable($this->director, $bad->ulid)));
        $this->actingAs($this->director)->get("/evidence/{$bad->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.integrity', 'mismatch')->where('canDownload', false));

        // Restoring the bytes changes nothing: the mark is final, and marked blobs are not checked again.
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->put($blob->storage_key, 'Synthetic report, as captured.');
        $this->assertSame('skipped', app(EvidenceIntegrityService::class)->verify($blob->id));
        $this->assertSame(0, Artisan::call('evidence:verify-integrity'));
        $this->assertSame(2, DB::table('outbox_events')->count());
    }

    public function test_a_missing_object_fails_the_check_and_a_withdrawn_asset_is_left_alone(): void
    {
        $withdrawn = $this->stored('Synthetic bytes.');
        $kept = $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $withdrawn->evidence_blob_id]);
        $blob = EvidenceBlob::query()->findOrFail($withdrawn->evidence_blob_id);
        $this->withdrawals()->withdraw($this->director, $withdrawn, 'Not reliable');
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->delete($blob->storage_key);

        $this->assertSame('missing', app(EvidenceIntegrityService::class)->verify($blob->id));
        $this->assertSame('mismatch', $blob->refresh()->integrity_status);
        $this->assertSame(['integrity_failed'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $kept->id)->pluck('event')->all());
        $this->assertSame(['withdrawn'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $withdrawn->id)->pluck('event')->all());
        $this->assertEquals(['result' => 'missing', 'sha256' => $blob->sha256], json_decode((string) DB::table('audit_events')->where('action', 'evidence.integrity_failed')->sole()->context, true));
    }

    public function test_the_command_takes_the_least_recently_verified_first_and_validates_its_limit(): void
    {
        $older = $this->stored('Older check.');
        $never = $this->stored('Never checked.');
        DB::table('evidence_blobs')->where('id', $older->evidence_blob_id)->update(['last_verified_at' => now()->subDays(3)]);

        $this->assertSame(0, Artisan::call('evidence:verify-integrity', ['--limit' => 1]));
        $this->assertNotNull(EvidenceBlob::query()->findOrFail($never->evidence_blob_id)->last_verified_at);
        $this->assertTrue(EvidenceBlob::query()->findOrFail($older->evidence_blob_id)->last_verified_at?->lessThan(now()->subDay()));

        foreach (['0', '-1', 'ten', '10001'] as $limit) {
            $this->assertSame(2, Artisan::call('evidence:verify-integrity', ['--limit' => $limit]));
        }
    }

    public function test_versions_are_read_from_the_s3_store_and_only_a_missing_one_is_missing(): void
    {
        config(['filesystems.disks.evidence.key' => 'synthetic-key', 'filesystems.disks.evidence.secret' => 'synthetic-secret']);
        Storage::forgetDisk(EvidenceScanService::EVIDENCE_DISK);
        $disk = Storage::disk(EvidenceScanService::EVIDENCE_DISK);
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $requests = [];
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler([
            function (CommandInterface $command) use (&$requests): Result {
                $requests[] = [$command->getName(), $command['VersionId']];

                return new Result(['Body' => Utils::streamFor('Synthetic stored bytes.')]);
            },
            fn (CommandInterface $command) => throw new AwsException('No such version.', $command, ['code' => 'NoSuchVersion']),
            fn (CommandInterface $command) => throw new AwsException('Synthetic outage.', $command, ['code' => 'ServiceUnavailable']),
        ]));

        $stream = LockedObjects::readVersion($disk, 'sha256/abc', 'v1');
        $this->assertIsResource($stream);
        $this->assertSame('Synthetic stored bytes.', stream_get_contents($stream));
        fclose($stream);
        $this->assertSame([['GetObject', 'v1']], $requests);
        $this->assertNull(LockedObjects::readVersion($disk, 'sha256/abc', 'v2'));
        $this->assertThrows(fn () => LockedObjects::readVersion($disk, 'sha256/abc', 'v3'), AwsException::class);
    }

    private function withdrawals(): EvidenceWithdrawalService
    {
        return app(EvidenceWithdrawalService::class);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, EvidenceCustodyEvent::query()->count());
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['evidence.withdrawn', 'evidence.integrity_failed'])->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    /** Available evidence whose blob holds these bytes on the fake store, under their real SHA-256. */
    private function stored(string $bytes): EvidenceAsset
    {
        $key = 'sha256/'.Str::random(60);
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->put($key, $bytes);

        return $this->available(sha256: hash('sha256', $bytes), key: $key);
    }

    private function available(Classification $classification = Classification::Internal, ?string $sha256 = null, ?string $key = null): EvidenceAsset
    {
        $blobId = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => $sha256 ?? hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => $key ?? 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);

        return $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blobId, 'classification' => $classification]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill($attributes + [
            'ulid' => (string) Str::ulid(), 'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic withdrawal',
            'captured_at' => now(), 'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal,
            'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset;
    }

    private function expectValidation(callable $call, string $field, ?string $message = null): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on $field.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            if ($message !== null) {
                $this->assertSame([$message], $e->errors()[$field]);
            }
        }
    }
}
