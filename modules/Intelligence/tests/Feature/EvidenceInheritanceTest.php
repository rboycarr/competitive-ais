<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-36b / docs/07 §3 / docs/02 §3.5: evidence a claim cites inherits the item's competitors and markets as scopes
 * (all-of, through AccessScope), so evidence supporting an item about competitor A is never visible to someone without
 * a grant covering A. Inherited scopes are recorded (custody and audit), monotonic (unlinking or removing a subject
 * never removes one), follow subjects added later, and nobody cites or edits their way out of seeing the evidence.
 */
class EvidenceInheritanceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_evidence_scoped_only_by_inheritance_follows_the_matrix(): void
    {
        $users = AuthorizationMatrix::users();
        // Items need a subject: the unscoped shapes are P1-36a's (declared) matrix.
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] !== [] || $r['markets'] !== []));
        $writer = $this->writer();
        $ulids = [];
        foreach ($records as $record) {
            $asset = $this->available($writer, Classification::from($record['classification']));
            [$item, $claim] = $this->itemWithClaim($writer, $record['competitors'], $record['markets']);
            $this->claims()->link($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => $item->version]);
            $this->assertSame([$record['competitors'], $record['markets']], [$asset->competitorIds(), $asset->marketIds()], $record['label']);
            $ulids[$record['label']] = $asset->ulid;
        }

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = EvidenceAsset::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'inherited scopes, EvidenceAsset::visibleTo()');
    }

    public function test_inherited_scopes_are_recorded_monotonic_and_follow_new_subjects(): void
    {
        AuthorizationMatrix::users();
        [$a, $b, $m] = [AuthorizationMatrix::competitorId('A'), AuthorizationMatrix::competitorId('B'), AuthorizationMatrix::marketId('M')];
        $writer = $this->writer();
        $asset = $this->available($writer, Classification::Internal, [$a]);
        [$item, $claim] = $this->itemWithClaim($writer, [$b], []);

        $this->claims()->link($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => $item->version]);
        $this->assertSame([[$a, 'declared'], [$b, 'inherited']], DB::table('evidence_asset_competitor')->where('evidence_asset_id', $asset->id)
            ->orderBy('competitor_id')->get(['competitor_id', 'origin'])->map(fn ($r) => [(int) $r->competitor_id, $r->origin])->all());
        $event = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->where('event', 'scope_inherited')->sole();
        $this->assertSame(['user', $writer->id], [$event->actor_type, $event->actor_id]);
        $this->assertEquals(['item' => $item->ulid, 'competitor_ids' => [$b], 'market_ids' => []], $event->details); // JSON keeps no key order
        $audit = DB::table('audit_events')->where('action', 'evidence.scope_inherited')->sole();
        $this->assertSame([$asset->ulid, 'internal'], [$audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['competitors' => 1, 'item_ulid' => $item->ulid, 'markets' => 0], json_decode((string) $audit->context, true));

        // Citing it again adds nothing; a market added to the item later is inherited too.
        $this->claims()->link($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'context', 'version' => $item->version]);
        $this->assertSame(1, EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count());
        $this->items()->update($writer, $item, $this->itemFields([$b], [$m]) + ['version' => $item->version]);
        $this->assertSame([[$a, $b], [$m]], [$asset->competitorIds(), $asset->marketIds()]);
        $this->assertSame(2, EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count());

        // Monotonic: removing the subjects and the citations leaves every scope.
        $this->items()->update($writer, $item->refresh(), $this->itemFields([], [$m]) + ['version' => $item->version]);
        foreach (['supports', 'context'] as $relation) {
            $this->claims()->unlink($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => $relation, 'version' => $item->version]);
        }
        $this->assertSame(0, ClaimEvidenceLink::query()->count());
        $this->assertSame([[$a, $b], [$m]], [$asset->competitorIds(), $asset->marketIds()]);
        $this->assertSame(2, EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count());
    }

    /** TASK-0086: a product subject passes on its competitor; a withdrawn claim's citations still inherit new subjects. */
    public function test_product_subjects_and_withdrawn_claims_pass_their_scopes_on(): void
    {
        AuthorizationMatrix::users();
        [$a, $b, $m] = [AuthorizationMatrix::competitorId('A'), AuthorizationMatrix::competitorId('B'), AuthorizationMatrix::marketId('M')];
        $writer = $this->writer();
        $product = (int) DB::table('products')->insertGetId([
            'ulid' => (string) Str::ulid(), 'owner_kind' => 'competitor', 'competitor_id' => $b, 'name' => 'Matrix widget', 'status' => 'active',
            'classification' => 'internal', 'created_by' => $writer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = $this->items()->create($writer, ['products' => [DB::table('products')->where('id', $product)->value('ulid')]] + $this->itemFields([], []));
        $claim = $this->claims()->add($writer, $item, ['statement' => 'Synthetic claim', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);
        $asset = $this->available($writer, Classification::Internal);
        $this->claims()->link($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]);
        $this->assertSame([[$b], []], [$asset->competitorIds(), $asset->marketIds()], 'the product brings its competitor');

        $this->claims()->withdraw($writer, $item, $claim->ulid, ['version' => 3]);
        $this->items()->update($writer, $item, ['products' => [DB::table('products')->where('id', $product)->value('ulid')]] + $this->itemFields([$a], [$m]) + ['version' => 4]);
        $this->assertSame([[$a, $b], [$m]], [$asset->competitorIds(), $asset->marketIds()], 'cited by a withdrawn claim, still inherits');
    }

    /** TASK-0086: repeated IDs are written once; an unknown one fails the whole change, scopes and records together. */
    public function test_inherit_writes_repeats_once_and_an_unknown_id_changes_nothing(): void
    {
        AuthorizationMatrix::users();
        [$a, $m] = [AuthorizationMatrix::competitorId('A'), AuthorizationMatrix::marketId('M')];
        $writer = $this->writer();
        $asset = $this->available($writer, Classification::Internal);
        $linking = app(EvidenceLinking::class);
        $ulid = (string) Str::ulid();

        $this->assertTrue(DB::transaction(fn () => $linking->inherit($writer, $asset->id, $ulid, [$a, $a], [$m, $m])));
        $this->assertFalse(DB::transaction(fn () => $linking->inherit($writer, $asset->id, $ulid, [$a], [$m])), 'nothing new: no records');
        $this->assertSame([[$a], [$m], 1], [$asset->competitorIds(), $asset->marketIds(), EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count()]);

        $this->assertThrows(fn () => DB::transaction(fn () => $linking->inherit($writer, $asset->id, $ulid, [$a, PHP_INT_MAX], [])), QueryException::class);
        $this->assertSame([[$a], 1, 1], [$asset->competitorIds(), EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count(),
            DB::table('audit_events')->where('action', 'evidence.scope_inherited')->count()]);
    }

    /** TASK-0086: if any write of a citation fails, its inherited scopes, records, link and version go with it. */
    public function test_a_failed_write_leaves_no_inherited_scope(): void
    {
        AuthorizationMatrix::users();
        $writer = $this->writer();
        $asset = $this->available($writer, Classification::Internal);
        [$item, $claim] = $this->itemWithClaim($writer, [AuthorizationMatrix::competitorId('A')], []);
        EvidenceCustodyEvent::creating(function (EvidenceCustodyEvent $event): void {
            if ($event->event === 'linked') {
                throw new RuntimeException('custody store unavailable');
            }
        });

        $this->assertThrows(fn () => $this->claims()->link($writer, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]), RuntimeException::class);
        $this->assertSame([[], 0, 0, 0, 2], [$asset->competitorIds(), ClaimEvidenceLink::query()->count(), EvidenceCustodyEvent::query()->count(),
            DB::table('audit_events')->whereIn('action', ['evidence.scope_inherited', 'intelligence.evidence_linked'])->count(), $item->fresh()?->version]);
    }

    public function test_nobody_cites_or_edits_their_way_out_of_seeing_evidence(): void
    {
        AuthorizationMatrix::users();
        [$a, $b] = [AuthorizationMatrix::competitorId('A'), AuthorizationMatrix::competitorId('B')];
        // Sees confidential evidence about A, but only internal records about B.
        $writer = $this->createUser('narrow@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grant($writer, $a, Classification::Confidential);
        $this->grant($writer, $b, Classification::Internal);
        $asset = $this->available($writer, Classification::Confidential, [$a]);

        [$aboutB, $claimB] = $this->itemWithClaim($writer, [$b], []);
        $this->expectValidation(fn () => $this->claims()->link($writer, $aboutB, $claimB->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]), 'evidence');
        $this->assertSame([[$a], 2, 0, 0], [$asset->competitorIds(), $aboutB->refresh()->version, ClaimEvidenceLink::query()->count(),
            EvidenceCustodyEvent::query()->where('event', 'scope_inherited')->count()], 'a refused citation changes nothing');

        // Cited by an item about A (nothing new to inherit); adding B to that item is refused, and leaves everything.
        [$aboutA, $claimA] = $this->itemWithClaim($writer, [$a], []);
        $this->claims()->link($writer, $aboutA, $claimA->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]);
        $this->expectValidation(fn () => $this->items()->update($writer, $aboutA, $this->itemFields([$a, $b], []) + ['version' => 3]), 'subjects');
        $this->assertSame([[$a], [$a], 3], [$asset->competitorIds(), $aboutA->refresh()->competitorIds(), $aboutA->version]);

        // Evidence the editor already cannot see does not stop the edit: it simply inherits.
        $hidden = $this->available($this->writer(), Classification::Restricted);
        $owner = $this->writer('owner@example.test');
        [$shared, $sharedClaim] = $this->itemWithClaim($owner, [$a], []);
        $this->claims()->link($owner, $shared, $sharedClaim->ulid, ['evidence' => $hidden->ulid, 'relation' => 'supports', 'version' => 2]);
        DB::table('intelligence_items')->where('id', $shared->id)->update(['owner_id' => $writer->id]);
        $this->items()->update($writer, $shared->refresh(), $this->itemFields([$a, $b], []) + ['version' => 3]);
        $this->assertSame([$a, $b], $hidden->competitorIds());
    }

    private function writer(string $email = 'writer@example.test'): User
    {
        $writer = User::query()->where('email', $email)->first();
        if ($writer !== null) {
            return $writer;
        }
        $writer = $this->createUser($email, 'ci_analyst', clearance: Classification::Restricted);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $writer->id, 'scope_type' => 'all', 'max_classification' => 'restricted',
            'granted_by' => $writer->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $writer;
    }

    private function grant(User $user, int $competitorId, Classification $level): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'competitor', 'competitor_id' => $competitorId, 'market_id' => null,
            'max_classification' => $level->value, 'granted_by' => $this->writer()->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     * @return array{IntelligenceItem, IntelligenceClaim}
     */
    private function itemWithClaim(User $writer, array $competitors, array $markets): array
    {
        $item = $this->items()->create($writer, $this->itemFields($competitors, $markets));
        $claim = $this->claims()->add($writer, $item, ['statement' => 'Synthetic claim', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1]);

        return [$item, $claim];
    }

    /**
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     * @return array<string, mixed>
     */
    private function itemFields(array $competitors, array $markets): array
    {
        $ulids = fn (string $table, array $ids): array => DB::table($table)->whereIn('id', $ids)->pluck('ulid')->all();

        return [
            'title' => 'Synthetic item', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal',
            'competitors' => $ulids('competitors', $competitors), 'products' => [], 'markets' => $ulids('markets', $markets),
        ];
    }

    /** @param  list<int>  $competitors  declared at upload */
    private function available(User $uploader, Classification $classification, array $competitors = []): EvidenceAsset
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob,
            'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence', 'captured_at' => now()->subDay(),
            'uploaded_by' => $uploader->id, 'classification' => $classification, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();
        foreach ($competitors as $id) {
            DB::table('evidence_asset_competitor')->insert(['evidence_asset_id' => $asset->id, 'competitor_id' => $id, 'origin' => 'declared', 'added_by' => $uploader->id, 'created_at' => now()]);
        }

        return $asset;
    }

    private function claims(): IntelligenceClaimService
    {
        return app(IntelligenceClaimService::class);
    }

    private function items(): IntelligenceItemService
    {
        return app(IntelligenceItemService::class);
    }

    private function expectValidation(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on $field.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
