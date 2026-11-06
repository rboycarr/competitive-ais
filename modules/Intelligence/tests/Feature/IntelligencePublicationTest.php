<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Intelligence\Models\Retraction;
use Modules\Intelligence\Models\VerificationReview;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-44 / docs/03 Intelligence (state machine: publish, retract, supersede), docs/02 §3.6 (publish invariant), docs/07 §3
 * (segregation of duties): a director publishes an approved item only with an approval for its current version whose
 * content hash still matches, never as its author, owner or approver; confidential, restricted or high-impact items need
 * the Director role. Published items are retracted with a reason (an append-only record, optionally naming a
 * replacement) or superseded by a replacement drafted from them, once that is published. Hidden items are 404 for every
 * one of these, whatever the input.
 */
class IntelligencePublicationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    private User $reviewer;

    private User $director;

    private User $deputy;

    private string $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->reviewer = $this->createUser('reviewer@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->deputy = $this->enableTwoFactor($this->createUser('deputy@example.test', 'ci_director', clearance: Classification::Confidential));
        foreach ([$this->analyst, $this->reviewer, $this->director, $this->deputy] as $user) {
            $this->grantAll($user);
        }
        $this->acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $this->acme, 'name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_director_publishes_an_approved_item_for_everyone_with_access(): void
    {
        $item = $this->approved();
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $this->grantAll($reader);
        $this->actingAs($reader)->get("/intelligence/{$item->ulid}")->assertNotFound();

        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.canPublish', true)->where('publication.publishProblems', []));
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/publish", ['version' => $item->version])
            ->assertSessionHasNoErrors()->assertRedirect("/intelligence/{$item->ulid}");

        $item->refresh();
        $this->assertSame('published', $item->status);
        $this->assertNotNull($item->released_at);
        $approve = VerificationReview::query()->where('decision', 'approve')->sole();
        $publish = VerificationReview::query()->where('decision', 'publish')->sole();
        $this->assertSame([$this->director->id, $item->version, $approve->content_hash], [$publish->reviewer_id, $publish->item_version, $publish->content_hash]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.published')->sole();
        $this->assertEquals(['approval_ulid' => $approve->ulid, 'review_ulid' => $publish->ulid, 'version' => $item->version], json_decode((string) $audit->context, true));

        $this->actingAs($reader)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('publication.canPublish', false)->where('publication.canRetract', false)->where('publication.canReplace', false)
            ->where('publication.releasedAt', $item->released_at->toIso8601ZuluString()));
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item, ['version' => $item->version]), 'item', 'Only an approved item can be published.');
    }

    public function test_author_owner_and_approver_never_publish(): void
    {
        // The approver: the director reviewed it, so the deputy publishes.
        $item = $this->approved(reviewer: $this->director, assigner: $this->deputy);
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/publish", ['version' => $item->version])->assertForbidden();
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.publishProblems', ['You approved it, so someone else must publish it.']));

        // The owner.
        DB::table('intelligence_items')->where('id', $item->id)->update(['owner_id' => $this->deputy->id]);
        $this->actingAs($this->deputy)->post("/intelligence/{$item->ulid}/publish", ['version' => $item->version])->assertForbidden();
        $this->actingAs($this->deputy)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('publication.canPublish', false));

        // The author (a director who wrote it).
        $own = $this->approved(author: $this->deputy);
        $this->actingAs($this->deputy)->post("/intelligence/{$own->ulid}/publish", ['version' => $own->version])->assertForbidden();

        // Without intelligence.publish.
        $this->actingAs($this->reviewer)->post("/intelligence/{$own->ulid}/publish", ['version' => $own->version])->assertForbidden();
        $this->assertSame(['approved', 'approved'], [$item->refresh()->status, $own->refresh()->status]);
        $this->assertSame(0, VerificationReview::query()->where('decision', 'publish')->count());
    }

    public function test_publication_needs_an_approval_of_the_current_content(): void
    {
        $reviewing = $this->submitted();
        $this->workflow()->assign($this->director, $reviewing, ['reviewer' => $this->reviewer->ulid]);
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $reviewing, ['version' => $reviewing->version]), 'item', 'Only an approved item can be published.');

        $item = $this->approved();
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item, ['version' => $item->version - 1]), 'version');
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item, []), 'version');

        // Content changed behind the workflow's back: the approval's hash no longer matches.
        $claim = (int) DB::table('intelligence_claims')->where('intelligence_item_id', $item->id)->value('id');
        DB::table('intelligence_claims')->where('id', $claim)->update(['statement' => 'Something else']);
        $changed = 'This item changed after it was approved. It must be reviewed again.';
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item->refresh(), ['version' => $item->version]), 'item', $changed);
        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.publishProblems', ['It changed after it was approved. It must be reviewed again.']));
        DB::table('intelligence_claims')->where('id', $claim)->update(['statement' => 'Acme sells the widget']);

        // An approval of another version never counts.
        DB::table('intelligence_items')->where('id', $item->id)->update(['version' => $item->version + 1]);
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item->refresh(), ['version' => $item->version]), 'item', 'This item has no approval for its current version.');
        DB::table('intelligence_items')->where('id', $item->id)->update(['version' => $item->version - 1]);

        // Evidence withdrawn since the approval.
        $asset = (int) DB::table('claim_evidence_links')->where('intelligence_claim_id', $claim)->value('evidence_asset_id');
        DB::table('evidence_assets')->where('id', $asset)->update(['status' => 'withdrawn', 'evidence_blob_id' => null, 'legal_hold' => false]);
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $item->refresh(), ['version' => $item->version]), 'claims');
        $this->assertSame('approved', $item->refresh()->status);
        $this->assertSame(0, VerificationReview::query()->where('decision', 'publish')->count());
    }

    public function test_confidential_and_high_impact_items_need_the_director_role(): void
    {
        $publisher = $this->enableTwoFactor($this->createUser('publisher@example.test', 'ci_analyst', clearance: Classification::Confidential));
        $this->grantAll($publisher);
        Role::create(['name' => 'publisher', 'guard_name' => 'web'])->givePermissionTo(['intelligence.view', 'intelligence.publish']);
        $publisher->assignRole('publisher');

        $message = 'Confidential, restricted or high-impact intelligence is published by a director.';
        foreach ([['confidential', false], ['internal', true]] as [$classification, $highImpact]) {
            $item = $this->approved(classification: $classification, highImpact: $highImpact);
            $this->actingAs($publisher)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page->where('publication.publishProblems', [$message]));
            $this->actingAs($publisher)->post("/intelligence/{$item->ulid}/publish", ['version' => $item->version])->assertForbidden();
            $this->workflow()->publish($this->director, $item, ['version' => $item->version]);
            $this->assertSame('published', $item->refresh()->status);
        }
        $plain = $this->approved();
        $this->actingAs($publisher)->post("/intelligence/{$plain->ulid}/publish", ['version' => $plain->version])->assertSessionHasNoErrors();
        $this->assertSame('published', $plain->refresh()->status);

        // A suspended director is not a director.
        $users = app(UserDirectory::class);
        DB::table('users')->where('id', $this->deputy->id)->update(['status' => 'suspended']);
        $this->assertSame([false, true, false], [$users->lockedHoldsRole($this->deputy->id, 'ci_director'),
            $users->lockedHoldsRole($this->director->id, 'ci_director'), $users->lockedHoldsRole($publisher->id, 'ci_director')]);
    }

    public function test_a_published_item_is_retracted_with_a_reason(): void
    {
        $item = $this->published();
        $other = $this->published();
        $hidden = $this->published(classification: 'confidential');
        DB::table('intelligence_items')->where('id', $hidden->id)->update(['classification' => 'restricted']);
        $draft = $this->draft();

        $this->actingAs($this->analyst)->post("/intelligence/{$item->ulid}/retract", ['reason' => 'Wrong'])->assertForbidden();
        $this->expectValidation(fn () => $this->workflow()->retract($this->director, $item, ['reason' => "  \n "]), 'reason', 'Give the reason for retracting it.');
        $this->expectValidation(fn () => $this->workflow()->retract($this->director, $item, ['reason' => ['x']]), 'reason');
        foreach ([$item->ulid, $hidden->ulid, $draft->ulid, (string) Str::ulid(), 'not-a-ulid', ['x']] as $replacement) {
            $this->expectValidation(fn () => $this->workflow()->retract($this->director, $item, ['reason' => 'Wrong', 'replacement' => $replacement]), 'replacement',
                'The replacement must be another published item you can see.');
        }

        $this->actingAs($this->director)->get("/intelligence/{$item->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.canRetract', true)->where('publication.replacementOptions', [['ulid' => $other->ulid, 'title' => $other->title]]));
        $this->actingAs($this->director)->post("/intelligence/{$item->ulid}/retract", ['reason' => '  The widget was never sold.  ', 'replacement' => strtolower($other->ulid)])
            ->assertSessionHasNoErrors();

        $this->assertSame('retracted', $item->refresh()->status);
        $retraction = Retraction::query()->sole();
        $this->assertSame([$item->id, 'The widget was never sold.', $this->director->id, $other->id],
            [$retraction->intelligence_item_id, $retraction->reason, $retraction->retracted_by, $retraction->replacement_item_id]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.retracted')->sole();
        $this->assertEquals(['replacement' => true, 'version' => $item->version], json_decode((string) $audit->context, true));

        // Still readable, marked as retracted, by everyone who could read it.
        $reader = $this->createUser('reader@example.test', 'exec_reader', clearance: Classification::Confidential);
        $this->grantAll($reader);
        $this->actingAs($reader)->get("/intelligence/{$item->ulid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('publication.retraction.reason', 'The widget was never sold.')->where('publication.retraction.by', $this->director->name)
            ->where('publication.retraction.replacement.ulid', $other->ulid)->where('publication.canRetract', false));
        $this->expectValidation(fn () => $this->workflow()->retract($this->director, $item, ['reason' => 'Again']), 'item', 'Only a published item can be retracted.');

        // Append-only and checked by the database.
        $this->assertDatabaseRefuses(fn () => DB::table('retractions')->where('id', $retraction->id)->update(['reason' => 'Changed']), 'UPDATE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('retractions')->where('id', $retraction->id)->delete(), 'DELETE command denied');
        $this->assertDatabaseRefuses(fn () => DB::table('retractions')->insert(['intelligence_item_id' => $other->id, 'reason' => '   ', 'retracted_by' => $this->director->id, 'retracted_at' => now()]), 'Check constraint');
        $this->assertDatabaseRefuses(fn () => DB::table('retractions')->insert(['intelligence_item_id' => $other->id, 'reason' => 'Self', 'retracted_by' => $this->director->id, 'retracted_at' => now(), 'replacement_item_id' => $other->id]), 'Check constraint');
        $this->assertDatabaseRefuses(fn () => DB::table('retractions')->insert(['intelligence_item_id' => $item->id, 'reason' => 'Twice', 'retracted_by' => $this->director->id, 'retracted_at' => now()]), 'Duplicate entry');
    }

    public function test_a_replacement_supersedes_the_original_once_published(): void
    {
        $original = $this->published(highImpact: true);
        $this->expectValidation(fn () => $this->items()->draftReplacement($this->analyst, $this->draft()), 'item', 'Only a published item can be replaced.');

        $this->actingAs($this->analyst)->post("/intelligence/{$original->ulid}/replacement")->assertSessionHasNoErrors()->assertRedirect();
        $replacement = IntelligenceItem::query()->where('supersedes_id', $original->id)->sole();
        $this->assertSame(['draft', 1, $original->title, $original->summary, true, 'internal', $this->analyst->id],
            [$replacement->status, $replacement->version, $replacement->title, $replacement->summary, $replacement->high_impact, $replacement->classification->value, $replacement->author_id]);
        $this->assertSame($original->competitorIds(), $replacement->competitorIds());
        $this->assertSame(0, $replacement->claims()->count(), 'claims are added afresh');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'intelligence.replacement_drafted')->count());
        $this->actingAs($this->analyst)->get("/intelligence/{$replacement->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.supersedes.ulid', $original->ulid));

        $rival = $this->items()->draftReplacement($this->analyst, $original);
        $this->approve($this->evidenceBacked($replacement));
        $this->approve($this->evidenceBacked($rival));
        $this->workflow()->publish($this->director, $replacement->refresh(), ['version' => $replacement->version]);
        $this->assertSame(['published', 'superseded'], [$replacement->refresh()->status, $original->refresh()->status]);
        $audit = DB::table('audit_events')->where('action', 'intelligence.superseded')->sole();
        $this->assertSame($original->ulid, $audit->subject_ulid);
        $this->assertEquals(['replacement_ulid' => $replacement->ulid, 'version' => $original->version], json_decode((string) $audit->context, true));
        $this->actingAs($this->analyst)->get("/intelligence/{$original->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.supersededBy.ulid', $replacement->ulid)->where('publication.canRetract', false)->where('publication.canReplace', false));

        // The rival now replaces nothing that is published.
        $this->actingAs($this->director)->get("/intelligence/{$rival->ulid}")->assertInertia(fn ($page) => $page
            ->where('publication.publishProblems', ['The item it replaces is no longer published.']));
        $this->expectValidation(fn () => $this->workflow()->publish($this->director, $rival->refresh(), ['version' => $rival->version]), 'item',
            'The item this one replaces is no longer published, or you cannot see it.');
        // A superseded item cannot be retracted.
        $this->expectValidation(fn () => $this->workflow()->retract($this->director, $original, ['reason' => 'x']), 'item');
    }

    /** TASK-0090 #1: the item a replacement supersedes is set once, on its draft, and never changed or cleared. */
    public function test_the_replaced_item_is_never_changed(): void
    {
        $original = $this->published();
        $other = $this->published();
        $replacement = $this->items()->draftReplacement($this->analyst, $original);

        // Editing the draft keeps it.
        $this->items()->update($this->analyst, $replacement, [
            'title' => 'Widget relaunch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$this->acme],
            'version' => $replacement->version, 'supersedes_id' => $other->id,
        ]);
        $this->assertSame($original->id, $replacement->refresh()->supersedes_id);

        foreach ([$other->id, null] as $value) {
            $this->assertThrows(fn () => $replacement->refresh()->forceFill(['supersedes_id' => $value])->save(), \LogicException::class, 'never changes the item it replaces');
        }
        // Nor is it set on anything but a draft, nor together with leaving the draft (TASK-0091).
        $this->assertThrows(fn () => $other->refresh()->forceFill(['supersedes_id' => $original->id])->save(), \LogicException::class);
        $draft = $this->draft();
        $this->assertThrows(fn () => $draft->forceFill(['status' => 'published', 'supersedes_id' => $original->id])->save(), \LogicException::class);
        $this->assertSame([$original->id, null], [$replacement->refresh()->supersedes_id, $other->refresh()->supersedes_id]);
        $this->assertSame(['draft', null], [$draft->refresh()->status, $draft->supersedes_id]);
    }

    public function test_every_publication_route_is_404_for_a_hidden_item_whatever_the_input(): void
    {
        $approved = $this->approved();
        $published = $this->published();
        DB::table('intelligence_items')->whereIn('id', [$approved->id, $published->id])->update(['classification' => 'restricted']);
        foreach ([$this->director, $this->analyst, $this->reviewer] as $user) {
            foreach ([[$approved, 'publish', ['version' => $approved->version]], [$approved, 'publish', ['version' => ['x']]],
                [$published, 'retract', ['reason' => 'x']], [$published, 'retract', ['reason' => ['x'], 'replacement' => 'bad']], [$published, 'replacement', []]] as [$item, $path, $input]) {
                $this->actingAs($user)->post("/intelligence/{$item->ulid}/$path", $input)->assertNotFound();
            }
        }
        $this->assertSame(['approved', 'published'], [$approved->refresh()->status, $published->refresh()->status]);
        $this->assertSame(0, Retraction::query()->count());
    }

    private function draft(?User $author = null, string $classification = 'internal', bool $highImpact = false): IntelligenceItem
    {
        return $this->items()->create($author ?? $this->analyst, [
            'title' => 'Widget launch', 'summary' => 'Synthetic summary', 'item_type' => 'news', 'classification' => $classification,
            'high_impact' => $highImpact, 'competitors' => [$this->acme],
        ]);
    }

    /** A claim supported by available evidence on the draft, then submitted. */
    private function evidenceBacked(IntelligenceItem $item): IntelligenceItem
    {
        $author = User::query()->findOrFail($item->author_id);
        $claim = $this->claims()->add($author, $item, ['statement' => 'Acme sells the widget', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version]);
        $this->claims()->link($author, $item->refresh(), $claim->ulid, ['evidence' => $this->available($author)->ulid, 'relation' => 'supports', 'version' => $item->version]);
        $this->workflow()->submit($author, $item->refresh(), ['version' => $item->version]);

        return $item->refresh();
    }

    private function submitted(?User $author = null, string $classification = 'internal', bool $highImpact = false): IntelligenceItem
    {
        return $this->evidenceBacked($this->draft($author, $classification, $highImpact));
    }

    /** Assigned, reviewed and approved. */
    private function approve(IntelligenceItem $item, ?User $reviewer = null, ?User $assigner = null): IntelligenceItem
    {
        $reviewer ??= $this->reviewer;
        $this->workflow()->assign($assigner ?? $this->director, $item, ['reviewer' => $reviewer->ulid]);
        $this->workflow()->startReview($reviewer, $item);
        $this->workflow()->decide($reviewer, $item, ['decision' => 'approve', 'version' => $item->version]);

        return $item->refresh();
    }

    private function approved(?User $author = null, ?User $reviewer = null, ?User $assigner = null, string $classification = 'internal', bool $highImpact = false): IntelligenceItem
    {
        return $this->approve($this->submitted($author, $classification, $highImpact), $reviewer, $assigner);
    }

    private function published(string $classification = 'internal', bool $highImpact = false): IntelligenceItem
    {
        $item = $this->approved(classification: $classification, highImpact: $highImpact);
        $this->workflow()->publish($this->director, $item, ['version' => $item->version]);

        return $item->refresh();
    }

    private function available(User $uploader): EvidenceAsset
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
            'uploaded_by' => $uploader->id, 'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
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

    private function items(): IntelligenceItemService
    {
        return app(IntelligenceItemService::class);
    }

    private function claims(): IntelligenceClaimService
    {
        return app(IntelligenceClaimService::class);
    }

    private function workflow(): IntelligenceWorkflowService
    {
        return app(IntelligenceWorkflowService::class);
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

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            $statement();
            $this->fail("The database accepted a statement it should refuse ($message).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
