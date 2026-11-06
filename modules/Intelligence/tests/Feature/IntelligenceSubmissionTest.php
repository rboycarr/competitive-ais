<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-42 / docs/03 Intelligence (state machine, submit): an analyst submits an item for review only when it is
 * evidence-backed: at least one claim, and every claim not withdrawn has a `supports` link to available evidence.
 * Only the author or owner with intelligence.submit, from the version read, while the item is editable; submitting
 * freezes the content and is audited.
 */
class IntelligenceSubmissionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private string $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($this->analyst);
        $this->acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $this->acme, 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_evidence_backed_draft_is_submitted_and_frozen(): void
    {
        $item = $this->draft();
        $first = $this->claim($item);
        $second = $this->claim($item);
        $this->cite($item, $first, $this->available(), 'supports');
        $this->cite($item, $second, $this->available(), 'supports');
        $this->cite($item, $second, $this->available(), 'contradicts'); // contradicting evidence is allowed alongside support

        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page->where('submission', ['problems' => []]));
        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/submit", ['version' => $item->refresh()->version])
            ->assertSessionHasNoErrors()->assertRedirect("/intelligence/{$item->ulid}");

        $item->refresh();
        $this->assertSame(['submitted', 6], [$item->status, $item->version], 'the version stays: no content changed');
        $audit = DB::table('audit_events')->where('action', 'intelligence.submitted')->sole();
        $this->assertSame($item->ulid, $audit->subject_ulid);
        $this->assertEquals(['claims' => 2, 'version' => 6], json_decode((string) $audit->context, true));

        // Frozen: no content change, no second submission, and the page offers neither.
        $this->expectValidation(fn () => $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => 6]), 'item');
        $this->expectValidation(fn () => $this->workflow()->submit($this->analyst, $item, ['version' => 6]), 'item');
        $this->actingAs($this->analyst)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('submission', null)->where('canEdit', false));
    }

    public function test_every_claim_needs_available_supporting_evidence(): void
    {
        $item = $this->draft();
        $this->expectProblems($item, ['Add at least one claim.']);

        $first = $this->claim($item);
        $second = $this->claim($item);
        $this->cite($item, $first, $this->available(), 'supports');
        $this->cite($item, $second, $this->available(), 'context');
        $this->cite($item, $second, $this->available(), 'contradicts');
        $this->expectProblems($item, ['Claim 2 cites no available evidence that supports it.']);

        // Support from evidence that is no longer available does not count.
        $withdrawn = $this->available();
        $this->cite($item, $second, $withdrawn, 'supports');
        DB::table('evidence_assets')->where('id', $withdrawn->id)->update(['status' => 'withdrawn', 'evidence_blob_id' => null, 'legal_hold' => false]);
        $this->expectProblems($item, ['Claim 2 cites no available evidence that supports it.']);

        // A disputed claim still needs support; a withdrawn claim does not count.
        DB::table('intelligence_claims')->where('id', $second->id)->update(['status' => 'disputed']);
        $this->expectProblems($item, ['Claim 2 cites no available evidence that supports it.']);
        $this->claims()->withdraw($this->analyst, $item, $second->ulid, ['version' => $item->version]);
        $this->assertSame([], $this->workflow()->problems($item));

        // TASK-0087: one withdrawn support among several does not matter while another is available.
        $gone = $this->available();
        $this->cite($item, $first, $gone, 'supports');
        DB::table('evidence_assets')->where('id', $gone->id)->update(['status' => 'withdrawn', 'evidence_blob_id' => null, 'legal_hold' => false]);
        $this->assertSame([], $this->workflow()->problems($item->refresh()));
        $this->workflow()->submit($this->analyst, $item, ['version' => $item->version]);
        $this->assertSame('submitted', $item->refresh()->status);
    }

    public function test_only_the_author_or_owner_with_submit_permission_submits_an_editable_item_from_the_version_read(): void
    {
        $item = $this->draft();
        $this->cite($item, $this->claim($item), $this->available(), 'supports');
        $version = $item->version;

        // Another analyst (who can review, so sees the draft) is not its author or owner.
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grantAll($other);
        $this->actingAs($other)->post("/intelligence/{$item->ulid}/submit", ['version' => $version])->assertForbidden();
        $this->actingAs($other)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('submission', null));

        // data_collector writes intelligence but cannot submit it, even as its owner.
        $collector = $this->createUser('collector@example.test', 'data_collector', clearance: Classification::Confidential);
        $this->grantAll($collector);
        DB::table('intelligence_items')->where('id', $item->id)->update(['owner_id' => $collector->id]);
        $this->actingAs($collector)->post("/intelligence/{$item->ulid}/submit", ['version' => $version])->assertForbidden();
        $this->actingAs($collector)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page->where('submission', null));

        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/submit", [])->assertSessionHasErrors('version');
        $this->expectValidation(fn () => $this->workflow()->submit($this->analyst, $item, ['version' => $version - 1]), 'version');
        foreach (['submitted', 'in_review', 'approved', 'published', 'rejected', 'retracted', 'superseded'] as $status) {
            DB::table('intelligence_items')->where('id', $item->id)->update(['status' => $status]);
            $this->expectValidation(fn () => $this->workflow()->submit($this->analyst, $item->refresh(), ['version' => $version]), 'item');
        }

        // Back from changes requested: submitted again.
        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'changes_requested']);
        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/submit", ['version' => $version])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $item->refresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.submitted')->count());
    }

    /** TASK-0087 #1: an item the user cannot see is not found, with or without intelligence.submit. */
    public function test_a_hidden_item_is_not_found_whatever_the_permissions(): void
    {
        $item = app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Confidential launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'confidential', 'competitors' => [$this->acme],
        ]);
        $this->cite($item, $this->claim($item), $this->available(), 'supports');
        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'published']); // released: hidden only by its classification
        foreach (['sales_field' => 'without', 'ci_analyst' => 'with'] as $role => $label) {
            $user = $this->createUser("$role@example.test", $role); // internal clearance
            $this->grantAll($user);
            $this->actingAs($user)->post("/intelligence/{$item->ulid}/submit", ['version' => $item->version])->assertNotFound();
            $this->actingAs($user)->get("/intelligence/{$item->ulid}")->assertNotFound();
        }
        $this->assertSame(0, DB::table('audit_events')->where('action', 'intelligence.submitted')->count());
    }

    /** @param  list<string>  $problems */
    private function expectProblems(IntelligenceItem $item, array $problems): void
    {
        $this->assertSame($problems, $this->workflow()->problems($item->refresh()));
        try {
            $this->workflow()->submit($this->analyst, $item, ['version' => $item->version]);
            $this->fail('Submitted an item that is not evidence-backed.');
        } catch (ValidationException $e) {
            $this->assertSame($problems, $e->errors()['claims'] ?? null);
        }
        $this->assertSame('draft', $item->refresh()->status);
    }

    private function draft(): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$this->acme],
        ]);
    }

    private function claim(IntelligenceItem $item): IntelligenceClaim
    {
        return $this->claims()->add($this->analyst, $item, $this->claimFields() + ['version' => $item->version]);
    }

    private function cite(IntelligenceItem $item, IntelligenceClaim $claim, EvidenceAsset $asset, string $relation): void
    {
        $this->claims()->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset->ulid, 'relation' => $relation, 'version' => $item->version]);
    }

    /** @return array<string, mixed> */
    private function claimFields(): array
    {
        return ['statement' => 'Acme sells the widget', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2];
    }

    private function available(): EvidenceAsset
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
            'uploaded_by' => $this->analyst->id, 'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
        ])->save();

        return $asset;
    }

    private function grantAll(User $user): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function claims(): IntelligenceClaimService
    {
        return app(IntelligenceClaimService::class);
    }

    private function workflow(): IntelligenceWorkflowService
    {
        return app(IntelligenceWorkflowService::class);
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
