<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Support\IntelligenceContent;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-45 / docs/03 Intelligence (provenance trace), docs/07 §3: an item traced to its origins: claims → evidence →
 * content hash → source and policy version → custody → reviews → (for audit.view) the audit trail. Evidence, sources and
 * audit rows the viewer cannot see are counted or left out, never described; showing the audit trail is audited. The
 * evidence page names the intelligence citing it that the viewer can see, and counts the rest.
 */
class ProvenanceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private User $reviewer;

    private User $director;

    private string $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->reviewer = $this->createUser('reviewer@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        foreach ([$this->analyst, $this->reviewer, $this->director] as $user) {
            $this->grantAll($user);
        }
        $this->acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $this->acme, 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_published_item_is_traced_to_its_origins(): void
    {
        $open = $this->source('Public filings', 'internal');
        $secret = $this->source('Inside channel', 'confidential');
        $public = $this->available(Classification::Internal, $open);
        $private = $this->available(Classification::Confidential, $secret);
        $other = $this->available(Classification::Internal);

        $item = $this->draft();
        $first = $this->claim($item);
        $this->cite($item, $first, $public, 'supports', 'p. 4');
        $this->cite($item, $first, $private, 'context');
        $second = $this->claim($item);
        $this->cite($item, $second, $other, 'supports');
        $this->workflow()->submit($this->analyst, $item->refresh(), ['version' => $item->version]);
        $this->workflow()->assign($this->director, $item, ['reviewer' => $this->reviewer->ulid]);
        $this->workflow()->startReview($this->reviewer, $item);
        $this->workflow()->decide($this->reviewer, $item, ['decision' => 'approve', 'version' => $item->version]);
        $this->workflow()->publish($this->director, $item, ['version' => $item->version]);
        $hash = IntelligenceContent::hash($item->refresh());

        $auditor = $this->createUser('auditor@example.test', 'auditor', clearance: Classification::Confidential);
        $this->grantAll($auditor);
        $this->actingAs($auditor)->get("/intelligence/{$item->ulid}/provenance")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Intelligence/Provenance')
            ->where('content', ['hash' => $hash, 'approvedHash' => $hash, 'matchesApproval' => true])
            ->has('claims', 2)
            ->has('claims.0.evidence', 2)->where('claims.0.hiddenEvidence', 0)
            ->where('claims.0.evidence.0.relation', 'supports')->where('claims.0.evidence.0.locator', 'p. 4')
            ->where('claims.0.evidence.0.asset.ulid', $public->ulid)
            ->where('claims.0.evidence.0.asset.sha256', $this->sha($public))
            ->where('claims.0.evidence.0.asset.source.name', 'Public filings')->where('claims.0.evidence.0.asset.source.policyVersion', 1)
            ->where('claims.0.evidence.0.asset.custody', fn ($custody): bool => in_array('linked', array_column(is_array($custody) ? $custody : $custody->all(), 'event'), true))
            ->where('claims.0.evidence.1.asset.source.name', 'Inside channel')
            ->where('claims.1.evidence.0.asset.source', null)
            ->where('reviews', fn ($reviews): bool => array_column(is_array($reviews) ? $reviews : $reviews->all(), 'decision') === ['assign', 'approve', 'publish'])
            ->where('reviews.2.contentHash', $hash)
            ->where('audit.events', fn ($events): bool => in_array('intelligence.published', array_column(is_array($events) ? $events : $events->all(), 'action'), true))
            ->where('audit.more', false));
        $viewed = DB::table('audit_events')->where('action', 'intelligence.provenance_viewed')->sole();
        $this->assertSame([$item->ulid, $auditor->id], [$viewed->subject_ulid, (int) $viewed->actor_id]);

        // An auditor cleared only to internal: the confidential evidence is counted, its source not named.
        $internal = $this->createUser('internal@example.test', 'auditor', clearance: Classification::Internal);
        $this->grantAll($internal, 'internal');
        $this->actingAs($internal)->get("/intelligence/{$item->ulid}/provenance")->assertOk()->assertInertia(fn ($page) => $page
            ->has('claims.0.evidence', 1)->where('claims.0.hiddenEvidence', 1)
            ->where('claims.0.evidence.0.asset.ulid', $public->ulid)
            ->where('claims.0.evidence', fn ($evidence): bool => ! str_contains((string) json_encode($evidence), 'Inside channel') && ! str_contains((string) json_encode($evidence), $private->ulid)));

        // A reader without evidence.view or audit.view: every citation counted, no audit trail, nothing audited.
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $this->grantAll($reader);
        $this->actingAs($reader)->get("/intelligence/{$item->ulid}/provenance")->assertOk()->assertInertia(fn ($page) => $page
            ->where('claims.0.evidence', [])->where('claims.0.hiddenEvidence', 2)->where('claims.1.hiddenEvidence', 1)
            ->where('audit', null)->has('reviews', 3));
        $this->assertSame(2, DB::table('audit_events')->where('action', 'intelligence.provenance_viewed')->count());

        // Content changed behind the workflow's back no longer matches the approval.
        DB::table('intelligence_claims')->where('id', $first->id)->update(['statement' => 'Changed']);
        $this->actingAs($auditor)->get("/intelligence/{$item->ulid}/provenance")->assertInertia(fn ($page) => $page
            ->where('content.matchesApproval', false)->where('content.approvedHash', $hash));
    }

    /** TASK-0092 #1: the audit trail never names evidence (or any other record) the viewer cannot see. */
    public function test_audit_contexts_never_name_what_the_viewer_cannot_see(): void
    {
        $acme = (int) DB::table('competitors')->where('ulid', $this->acme)->value('id');
        $globex = (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => 'Globex', 'slug' => 'globex-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $seen = $this->available(Classification::Internal);
        $hidden = $this->available(Classification::Internal);
        // Also about Globex: an auditor covered only for Acme cannot see it.
        DB::table('evidence_asset_competitor')->insert(['evidence_asset_id' => $hidden->id, 'competitor_id' => $globex, 'origin' => 'declared', 'added_by' => $this->analyst->id, 'created_at' => now()]);
        $item = $this->draft();
        $claim = $this->claim($item);
        $this->cite($item, $claim, $seen, 'supports');
        $this->cite($item, $claim, $hidden, 'context');

        $auditor = $this->createUser('auditor@example.test', 'auditor', clearance: Classification::Confidential);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $auditor->id, 'scope_type' => 'competitor', 'competitor_id' => $acme, 'max_classification' => 'confidential',
            'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A draft is seen by holders of intelligence.review only: publish it straight into the auditor's view.
        DB::table('intelligence_items')->where('id', $item->id)->update(['status' => 'published', 'released_at' => now()]);

        $response = $this->actingAs($auditor)->get("/intelligence/{$item->ulid}/provenance")->assertOk();
        $events = $response->inertiaProps('audit.events');
        $linked = array_values(array_filter($events, fn (array $e): bool => $e['action'] === 'intelligence.evidence_linked'));
        $this->assertCount(2, $linked, 'both link events are visible audit rows');
        $this->assertEqualsCanonicalizing([$seen->ulid, 'hidden'], array_map(fn (array $e): string => $e['context']['evidence_ulid'], $linked));
        $this->assertSame([$claim->ulid, $claim->ulid], array_map(fn (array $e): string => $e['context']['claim_ulid'], $linked));
        $this->assertStringNotContainsString($hidden->ulid, (string) json_encode($response->inertiaProps()));
        $this->assertSame(1, $response->inertiaProps('claims.0.hiddenEvidence'));
    }

    /** TASK-0092 #4: the audit panel shows the item's first 200 events, then points to the audit log. */
    public function test_the_audit_panel_is_a_bounded_snapshot(): void
    {
        $item = $this->draft();
        $logger = app(AuditLogger::class);
        // The draft's creation is the first event; 199 more make 200.
        DB::transaction(function () use ($logger, $item): void {
            foreach (range(1, 199) as $i) {
                $logger->record('intelligence.review_started', $item, ['version' => 1], Actor::user($this->analyst->id));
            }
        });
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}/provenance")->assertInertia(fn ($page) => $page
            ->has('audit.events', 200)->where('audit.more', false));
        // Viewing it was recorded: now 201.
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}/provenance")->assertInertia(fn ($page) => $page
            ->has('audit.events', 200)->where('audit.more', true)->where('audit.events.0.action', 'intelligence.created'));
    }

    public function test_a_hidden_item_has_no_provenance(): void
    {
        $draft = $this->draft();
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $this->grantAll($reader);
        $this->actingAs($reader)->get("/intelligence/{$draft->ulid}/provenance")->assertNotFound();

        DB::table('intelligence_items')->where('id', $draft->id)->update(['classification' => 'restricted']);
        $this->actingAs($this->analyst)->get("/intelligence/{$draft->ulid}/provenance")->assertNotFound();
        $this->actingAs($this->analyst)->get('/intelligence/'.Str::ulid().'/provenance')->assertNotFound();
        $this->assertSame(0, DB::table('audit_events')->where('action', 'intelligence.provenance_viewed')->count());
    }

    public function test_the_evidence_page_names_the_intelligence_citing_it_that_the_viewer_can_see(): void
    {
        $asset = $this->available(Classification::Internal);
        $item = $this->draft();
        $claim = $this->claim($item);
        $this->cite($item, $claim, $asset, 'supports');
        $this->cite($item->refresh(), $claim, $asset, 'contradicts');
        $sha = $this->sha($asset);

        $this->actingAs($this->analyst)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('asset.sha256', $sha)
            ->where('citations', ['items' => [['ulid' => $item->ulid, 'title' => 'Widget launch', 'status' => 'draft', 'relations' => ['contradicts', 'supports']]], 'hidden' => 0]));

        // A collector sees the evidence but not someone else's draft: counted, not named.
        $collector = $this->createUser('collector@example.test', 'data_collector', clearance: Classification::Confidential);
        $this->grantAll($collector);
        $this->actingAs($collector)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('citations', ['items' => [], 'hidden' => 1]));

        $uncited = $this->available(Classification::Internal);
        $this->actingAs($this->analyst)->get("/evidence/{$uncited->ulid}")->assertInertia(fn ($page) => $page->where('citations', ['items' => [], 'hidden' => 0]));
    }

    private function draft(): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$this->acme],
        ]);
    }

    private function claim(IntelligenceItem $item): IntelligenceClaim
    {
        return app(IntelligenceClaimService::class)->add($this->analyst, $item->refresh(), [
            'statement' => 'Acme sells the widget', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version,
        ]);
    }

    private function cite(IntelligenceItem $item, IntelligenceClaim $claim, EvidenceAsset $asset, string $relation, ?string $locator = null): void
    {
        app(IntelligenceClaimService::class)->link($this->analyst, $item->refresh(), $claim->ulid, [
            'evidence' => $asset->ulid, 'relation' => $relation, 'locator' => $locator, 'version' => $item->version,
        ]);
    }

    private function source(string $name, string $classification): Source
    {
        $source = app(SourceService::class)->propose($this->analyst, ['name' => $name, 'source_type' => 'website', 'classification' => $classification, 'default_reliability' => 'B']);
        app(SourcePolicyService::class)->submit($this->analyst, $source);
        app(SourcePolicyService::class)->decide($this->director, $source, [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ]);

        return $source->refresh();
    }

    private function available(Classification $classification, ?Source $source = null): EvidenceAsset
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
            'uploaded_by' => $this->analyst->id, 'classification' => $classification, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
            'source_id' => $source?->id, 'source_policy_id' => $source?->current_policy_id,
        ])->save();

        return $asset;
    }

    private function sha(EvidenceAsset $asset): string
    {
        return (string) DB::table('evidence_blobs')->where('id', $asset->evidence_blob_id)->value('sha256');
    }

    private function grantAll(User $user, string $level = 'confidential'): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => $level,
            'granted_by' => $this->analyst->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function workflow(): IntelligenceWorkflowService
    {
        return app(IntelligenceWorkflowService::class);
    }
}
