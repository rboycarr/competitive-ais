<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Lookup\EloquentEvidenceLinking;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceCustodyEvent;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Access\AccessScope;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-41 / docs/02 §3.6 / docs/03 Intelligence: an analyst links a claim to evidence as supporting, contradicting or
 * giving context, with a locator and excerpt. Only available evidence the writer can see is citable (under a share
 * lock on the asset); links change only while the item is editable, by its author or owner, from the version read,
 * and raise the version. Each change is audited (IDs and codes only) and recorded in the asset's custody trail.
 * Citations are described only to viewers who can see the evidence, beside the source's default reliability.
 */
class ClaimEvidenceLinkTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($this->analyst, 'confidential');
        $this->acme = (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_analyst_cites_available_evidence_with_a_locator_and_excerpt(): void
    {
        [$item, $claim] = $this->draftWithClaim(); // version 2
        $asset = $this->available();

        $this->actingAs($this->analyst)->post($this->path($item, $claim), [
            'evidence' => strtolower($asset->ulid), 'relation' => 'supports', 'locator' => "  p.\t 4,  table 2 ", 'excerpt' => "Widget: \$9\r\nfrom Monday", 'version' => 2,
        ])->assertSessionHasNoErrors()->assertRedirect("/intelligence/{$item->ulid}");

        $link = ClaimEvidenceLink::query()->sole();
        $this->assertSame([$claim->id, $asset->id, 'supports', 'p. 4, table 2', "Widget: \$9\nfrom Monday", $this->analyst->id],
            [$link->intelligence_claim_id, $link->evidence_asset_id, $link->relation, $link->locator, $link->excerpt, $link->linked_by]);
        $this->assertSame(3, $item->refresh()->version);
        $audit = DB::table('audit_events')->where('action', 'intelligence.evidence_linked')->sole();
        $this->assertSame([$item->ulid, 'internal'], [$audit->subject_ulid, $audit->classification]);
        $this->assertSame(['claim_ulid' => $claim->ulid, 'evidence_ulid' => $asset->ulid, 'relation' => 'supports', 'version' => 3], $this->sorted(json_decode((string) $audit->context, true)));
        $custody = EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->where('event', 'linked')->sole();
        $this->assertSame(['user', $this->analyst->id, ['item' => $item->ulid, 'claim' => $claim->ulid, 'relation' => 'supports']], [$custody->actor_type, $custody->actor_id, $custody->details]);

        // Each asset and relation once; the same asset may also be cited another way.
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 3]), 'evidence');
        $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'context', 'version' => 3]);
        $this->assertSame([2, 4], [ClaimEvidenceLink::query()->count(), $item->refresh()->version]);

        // The relation is chosen, never assumed; a stale form is refused.
        foreach ([[], ['relation' => 'proves']] as $relation) {
            $this->actingAs($this->analyst)->post($this->path($item, $claim), ['evidence' => $asset->ulid, 'version' => 4] + $relation)->assertSessionHasErrors('relation');
        }
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'contradicts', 'version' => 3]), 'version');
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'contradicts', 'locator' => str_repeat('p', 256), 'version' => 4]), 'locator');

        // Stop citing: the link goes, the version rises, and the trail keeps both events.
        $this->actingAs($this->analyst)->post($this->path($item, $claim).'/remove', ['evidence' => $asset->ulid, 'relation' => 'context', 'version' => 4])->assertSessionHasNoErrors();
        $this->assertSame([['supports'], 5], [ClaimEvidenceLink::query()->pluck('relation')->all(), $item->refresh()->version]);
        $this->assertSame(['linked', 'linked', 'unlinked'], EvidenceCustodyEvent::query()->where('evidence_asset_id', $asset->id)->whereIn('event', ['linked', 'unlinked'])->orderBy('id')->pluck('event')->all());
        $this->expectValidation(fn () => $this->claims()->unlink($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'context', 'version' => 5]), 'evidence');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.evidence_unlinked')->count());
        $this->assertStringNotContainsString('Widget', (string) DB::table('audit_events')->where('action', 'like', 'intelligence.evidence_%')->pluck('context')->implode(' '));

        // The database holds the relation and non-blank texts (removing cited evidence: IntelligenceRaceTest, committed).
        foreach (['relation' => 'proves', 'locator' => ' ', 'excerpt' => '  '] as $column => $value) {
            $this->assertDatabaseRefuses(fn () => DB::table('claim_evidence_links')->where('id', $link->id)->update([$column => $value]), "claim_evidence_links_{$column}_check");
        }
    }

    public function test_only_available_evidence_the_writer_can_see_is_citable(): void
    {
        [$item, $claim] = $this->draftWithClaim();
        $citable = $this->available(title: 'Citable price list');
        $restricted = $this->available(Classification::Restricted, 'Restricted memo');
        $quarantined = $this->asset(['status' => 'quarantined', 'scan_status' => 'pending', 'quarantine_key' => 'quarantine/'.Str::ulid(), 'title' => 'Pending scan']);
        $rejected = $this->asset(['status' => 'rejected', 'scan_status' => 'infected', 'scanned_at' => now(), 'title' => 'Infected file']);

        foreach ([$restricted, $quarantined, $rejected] as $asset) {
            $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]), 'evidence');
        }
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => (string) Str::ulid(), 'relation' => 'supports', 'version' => 2]), 'evidence');
        $this->assertSame(0, ClaimEvidenceLink::query()->count());
        $this->assertSame(2, $item->refresh()->version, 'a refused link changes nothing');

        // The picker offers the same: available evidence the writer sees, searched by title (LIKE wildcards literal).
        $linking = app(EvidenceLinking::class);
        $this->assertSame([$citable->ulid], array_map(fn ($e) => $e->ulid, $linking->linkable($this->analyst, null, 25)));
        $this->assertSame([$citable->ulid], array_map(fn ($e) => $e->ulid, $linking->linkable($this->analyst, 'price', 25)));
        $this->assertSame([], $linking->linkable($this->analyst, '%', 25));
        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}?evidence_search=price")->assertOk()
            ->assertInertia(fn ($page) => $page->where('evidenceOptions.0.ulid', $citable->ulid)->count('evidenceOptions', 1)->where('evidenceSearch', 'price'));

        // Without evidence.view nothing is citable, even within clearance (data_collector has it; sales_field has neither).
        $sales = $this->createUser('sales@example.test', 'sales_field', clearance: Classification::Confidential);
        $this->assertSame([], $linking->linkable($sales, null, 25));
        $this->assertSame([], $linking->summaries($sales, [$citable->id]));

        // A withdrawn claim and an item past editing are frozen; only the author or owner cites.
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($other, 'confidential');
        $this->actingAs($other)->post($this->path($item, $claim), ['evidence' => $citable->ulid, 'relation' => 'supports', 'version' => 2])->assertForbidden();
        $this->claims()->withdraw($this->analyst, $item, $claim->ulid, ['version' => 2]);
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $citable->ulid, 'relation' => 'supports', 'version' => 3]), 'claim');
        $live = $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => 3]);
        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'submitted']);
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item->refresh(), $live->ulid, ['evidence' => $citable->ulid, 'relation' => 'supports', 'version' => 4]), 'item');
        $this->assertSame(0, ClaimEvidenceLink::query()->count());
    }

    public function test_a_claim_cites_at_most_twenty_pieces_of_evidence(): void
    {
        [$item, $claim] = $this->draftWithClaim();
        for ($i = 0; $i < IntelligenceClaimService::MAX_LINKS; $i++) {
            $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $this->available()->ulid, 'relation' => 'supports', 'version' => $item->version]);
        }
        $this->expectValidation(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $this->available()->ulid, 'relation' => 'supports', 'version' => $item->version]), 'evidence');
        $this->assertSame(IntelligenceClaimService::MAX_LINKS, ClaimEvidenceLink::query()->count());
    }

    /** TASK-0085: the link, its audit event, its custody event and the version commit together or not at all. */
    public function test_a_failed_custody_record_leaves_no_link_audit_or_version_change(): void
    {
        [$item, $claim] = $this->draftWithClaim();
        $asset = $this->available();
        $linking = Mockery::mock(EloquentEvidenceLinking::class, [app(Gate::class), app('db'), app(AccessScope::class), app(AuditLogger::class)])->makePartial();
        $linking->shouldReceive('recordLink')->andThrow(new RuntimeException('custody store unavailable'));
        $this->app->instance(EvidenceLinking::class, $linking);

        $this->assertThrows(fn () => $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => 'supports', 'version' => 2]),
            RuntimeException::class, 'custody store unavailable');

        $this->assertSame(0, ClaimEvidenceLink::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'intelligence.evidence_linked')->count());
        $this->assertSame(2, $item->fresh()?->version);
    }

    /** TASK-0085: limits count characters, not bytes; IDs in any case are accepted, malformed ones refused as fields. */
    public function test_limits_count_characters_and_identifiers_are_checked(): void
    {
        [$item, $claim] = $this->draftWithClaim();
        $asset = $this->available();
        $post = fn (array $data, string $suffix = '') => $this->actingAs($this->analyst)->post($this->path($item, $claim).$suffix, $data + ['version' => $item->refresh()->version]);

        $post(['evidence' => $asset->ulid, 'relation' => 'supports', 'locator' => str_repeat('😀', 256)])->assertSessionHasErrors('locator');
        $post(['evidence' => $asset->ulid, 'relation' => 'supports', 'excerpt' => str_repeat('é', IntelligenceClaimService::MAX_EXCERPT + 1)])->assertSessionHasErrors('excerpt');
        $post(['evidence' => $asset->ulid, 'relation' => 'supports', 'locator' => str_repeat('😀', 255), 'excerpt' => str_repeat('é', IntelligenceClaimService::MAX_EXCERPT)])->assertSessionHasNoErrors();
        $link = ClaimEvidenceLink::query()->sole();
        $this->assertSame([255, IntelligenceClaimService::MAX_EXCERPT], [mb_strlen((string) $link->locator), mb_strlen((string) $link->excerpt)]);

        foreach (['not-a-ulid', '', str_repeat('Z', 26)] as $malformed) {
            $post(['evidence' => $malformed, 'relation' => 'context'])->assertSessionHasErrors('evidence');
            $post(['evidence' => $malformed, 'relation' => 'supports'], '/remove')->assertSessionHasErrors('evidence');
        }
        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/claims/not-a-claim/evidence", ['evidence' => $asset->ulid, 'relation' => 'context', 'version' => $item->version])
            ->assertSessionHasErrors('claim');
        // Lower-case IDs name the same claim and asset.
        $this->actingAs($this->analyst)->post('/intelligence/'.$item->ulid.'/claims/'.strtolower($claim->ulid).'/evidence/remove', ['evidence' => strtolower($asset->ulid), 'relation' => 'supports', 'version' => $item->version])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, ClaimEvidenceLink::query()->count());
    }

    public function test_citations_are_described_only_to_those_who_can_see_the_evidence(): void
    {
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Restricted));
        $open = $this->source($director, 'Public filings', 'internal', 'B');
        $secret = $this->source($director, 'Inside channel', 'confidential', 'C');
        [$item, $claim] = $this->draftWithClaim();
        $internal = $this->available(title: 'Price list', source: $open);
        $hiddenSource = $this->available(title: 'Leaked deck', source: $secret);
        $confidential = $this->available(Classification::Confidential, 'Confidential memo');
        $unregistered = $this->available(title: 'Screenshot');
        foreach ([[$internal, 'supports', 'p. 4', '<script>alert(1)</script>'], [$hiddenSource, 'contradicts', null, null], [$confidential, 'supports', 'secret locator', 'secret excerpt'], [$unregistered, 'context', null, null]] as [$asset, $relation, $locator, $excerpt]) {
            $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => $relation, 'locator' => $locator, 'excerpt' => $excerpt, 'version' => $item->version]);
        }

        // An internal-cleared reviewer sees the item, two citations described, one source withheld, one citation counted.
        $reviewer = $this->createUser('reviewer@example.test', 'ci_analyst');
        $this->grantAll($reviewer, 'internal');
        $response = $this->actingAs($reviewer)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('claims.0.hiddenEvidence', 1)
            ->count('claims.0.evidence', 3)
            ->where('claims.0.evidence.0', [
                'ulid' => $internal->ulid, 'title' => 'Price list', 'status' => 'available', 'capturedAt' => $internal->captured_at->toIso8601ZuluString(),
                'relation' => 'supports', 'locator' => 'p. 4', 'excerpt' => '<script>alert(1)</script>',
                'source' => ['visible' => true, 'name' => 'Public filings', 'reliability' => 'B'],
            ])
            ->where('claims.0.evidence.1.source', ['visible' => false])
            ->where('claims.0.evidence.2.source', null)
            ->where('canEdit', false)
            ->where('evidenceOptions', []));
        $body = (string) $response->getContent();
        foreach (['Confidential memo', 'secret locator', 'secret excerpt', $confidential->ulid, 'Inside channel'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }

        // The author sees all four; once their clearance drops, they cannot remove a citation they can no longer see.
        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->count('claims.0.evidence', 4)->where('claims.0.hiddenEvidence', 0));
        DB::table('users')->where('id', $this->analyst->id)->update(['clearance' => 'internal']);
        $this->expectValidation(fn () => $this->claims()->unlink($this->analyst->refresh(), $item, $claim->ulid, ['evidence' => $confidential->ulid, 'relation' => 'supports', 'version' => $item->version]), 'evidence');
        $this->assertSame(4, ClaimEvidenceLink::query()->count());
    }

    /** @return array{IntelligenceItem, IntelligenceClaim} */
    private function draftWithClaim(): array
    {
        $item = app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'product_change', 'classification' => 'internal',
            'competitors' => [(string) DB::table('competitors')->where('id', $this->acme)->value('ulid')],
        ]);
        $claim = $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => 1]);

        return [$item, $claim];
    }

    /** @return array<string, mixed> */
    private function claimFields(): array
    {
        return ['statement' => 'Acme sells the widget at $9', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2];
    }

    private function claims(): IntelligenceClaimService
    {
        return app(IntelligenceClaimService::class);
    }

    private function path(IntelligenceItem $item, IntelligenceClaim $claim): string
    {
        return "/intelligence/{$item->ulid}/claims/{$claim->ulid}/evidence";
    }

    private function source(User $director, string $name, string $classification, string $reliability): Source
    {
        $source = app(SourceService::class)->propose($this->analyst, ['name' => $name, 'source_type' => 'website', 'classification' => $classification, 'default_reliability' => $reliability]);
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        app(SourcePolicyService::class)->decide($director, $source, [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ]);

        return $source->refresh();
    }

    private function available(Classification $classification = Classification::Internal, string $title = 'Synthetic evidence', ?Source $source = null): EvidenceAsset
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);

        return $this->asset(['status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob, 'classification' => $classification,
            'title' => $title, 'source_id' => $source?->id, 'source_policy_id' => $source?->current_policy_id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function asset(array $attributes): EvidenceAsset
    {
        $asset = new EvidenceAsset;
        $asset->forceFill($attributes + [
            'ulid' => (string) Str::ulid(), 'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence',
            'captured_at' => now()->subDay(), 'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal,
            'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset->refresh();
    }

    private function grantAll(User $user, string $level): void
    {
        $grantor = User::query()->where('email', 'grantor@example.test')->first()
            ?? $this->createUser('grantor@example.test', 'ci_director', clearance: Classification::Restricted);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => $level,
            'granted_by' => $grantor->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            $statement();
            $this->fail("The database accepted a statement it should refuse ($message).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
