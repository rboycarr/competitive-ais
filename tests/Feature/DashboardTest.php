<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-46 / docs/03 Dashboard: the start page shows each signed-in user the tiles their permissions allow, and every
 * count covers only records they can see (each module's own visibility rule). Guests and roles without a module's
 * permission get none of its tiles. Lives here, not in a module, because it spans Intelligence, Sources and Evidence.
 */
class DashboardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst');
        $this->grantAll($this->analyst);
    }

    public function test_a_guest_gets_no_tiles(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Platform/Home')->where('tiles', []));
    }

    public function test_tiles_follow_the_viewers_permissions(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $this->grantAll($reader);
        $service = $this->createUser('service@example.test', 'service_account');

        $this->assertSame(['intelligence_by_status', 'recently_published', 'verification_backlog', 'my_assignments', 'sources_by_status', 'evidence_attention'],
            array_column($this->tiles($this->analyst), 'key'));
        $this->assertSame(['intelligence_by_status', 'recently_published'], array_column($this->tiles($reader), 'key'));
        $this->assertSame([], $this->tiles($service));
    }

    public function test_intelligence_counts_cover_only_items_the_viewer_can_see(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $this->grantAll($reader);
        $this->item('Draft by the analyst', 'draft');
        $this->item('Published earlier', 'published', released: now()->subDays(2));
        $this->item('Published today', 'published', released: now()->subHour());
        $this->item('Restricted release', 'published', classification: 'restricted', released: now());

        // The reader sees released items only, within their clearance: not the draft, not the restricted one.
        $reader_tiles = $this->byKey($this->tiles($reader));
        $this->assertSame([['label' => 'published', 'value' => 2]], $reader_tiles['intelligence_by_status']['counts']);
        $this->assertSame(['Published today', 'Published earlier'], array_column($reader_tiles['recently_published']['items'], 'label'));

        // The analyst (author, and a reviewer) also sees the draft.
        $mine = $this->byKey($this->tiles($this->analyst));
        $this->assertSame([['label' => 'draft', 'value' => 1], ['label' => 'published', 'value' => 2]], $mine['intelligence_by_status']['counts']);
    }

    public function test_the_backlog_counts_by_age_and_my_assignments_lists_mine(): void
    {
        $other = $this->createUser('other@example.test', 'ci_analyst');
        $this->item('Fresh', 'submitted', submitted: now()->subHours(2), reviewer: $this->analyst->id);
        $this->item('Two days', 'in_review', submitted: now()->subDays(2), reviewer: $other->id);
        $this->item('Ten days', 'submitted', submitted: now()->subDays(10), reviewer: $this->analyst->id);
        $this->item('Decided', 'approved', submitted: now()->subDays(3), reviewer: $this->analyst->id);

        $tiles = $this->byKey($this->tiles($this->analyst));
        $this->assertSame([
            ['label' => 'under 1 day', 'value' => 1], ['label' => '1–3 days', 'value' => 1],
            ['label' => '3–7 days', 'value' => 0], ['label' => 'over 7 days', 'value' => 1],
        ], $tiles['verification_backlog']['counts']);
        $this->assertSame([['label' => 'assigned to me', 'value' => 2]], $tiles['my_assignments']['counts']);
        // Oldest submission first; the decided item is not in the queue.
        $this->assertSame(['Ten days', 'Fresh'], array_column($tiles['my_assignments']['items'], 'label'));
    }

    public function test_source_and_evidence_tiles_count_what_the_viewer_can_see(): void
    {
        $other = $this->createUser('other@example.test', 'ci_analyst');
        // Statuses without a policy (approved and suspended sources need one, sources_current_policy_check).
        foreach (['proposed', 'proposed', 'policy_review'] as $status) {
            $this->source($status);
        }
        $this->source('proposed', 'restricted');   // above the analyst's clearance
        $this->evidence('quarantined', $this->analyst);
        $this->evidence('rejected', $this->analyst);
        $this->evidence('quarantined', $other);     // someone else's upload in quarantine: not visible (O32)

        $tiles = $this->byKey($this->tiles($this->analyst));
        $this->assertSame([
            ['label' => 'proposed', 'value' => 2], ['label' => 'policy review', 'value' => 1], ['label' => 'approved', 'value' => 0],
            ['label' => 'suspended', 'value' => 0], ['label' => 'retired', 'value' => 0],
        ], $tiles['sources_by_status']['counts']);
        $this->assertSame([['label' => 'awaiting scan', 'value' => 1], ['label' => 'rejected', 'value' => 1]], $tiles['evidence_attention']['counts']);
    }

    /** @return list<array<string, mixed>> */
    private function tiles(User $user): array
    {
        $tiles = [];
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(function ($page) use (&$tiles) {
            $tiles = $page->toArray()['props']['tiles'];

            return $page->component('Platform/Home');
        });

        return $tiles;
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @return array<string, array<string, mixed>>
     */
    private function byKey(array $tiles): array
    {
        return array_column($tiles, null, 'key');
    }

    private function item(string $title, string $status, string $classification = 'internal', mixed $released = null, mixed $submitted = null, ?int $reviewer = null): void
    {
        DB::table('intelligence_items')->insert([
            'ulid' => (string) Str::ulid(), 'title' => $title, 'summary' => '', 'item_type' => 'news', 'classification' => $classification,
            'status' => $status, 'author_id' => $this->analyst->id, 'owner_id' => $this->analyst->id, 'assigned_reviewer_id' => $reviewer,
            'submitted_at' => $submitted, 'released_at' => $released, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function source(string $status, string $classification = 'internal'): void
    {
        $host = Str::lower(Str::random(8)).'.example';
        DB::table('sources')->insert([
            'ulid' => (string) Str::ulid(), 'name' => 'Synthetic '.$host, 'source_type' => 'website', 'base_url' => "https://$host/", 'host' => $host,
            'method' => 'manual', 'status' => $status, 'default_reliability' => 'F', 'owner_id' => $this->analyst->id, 'proposed_by' => $this->analyst->id,
            'classification' => $classification, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function evidence(string $status, User $uploader): void
    {
        $quarantined = $status === 'quarantined';
        DB::table('evidence_assets')->insert([
            'ulid' => (string) Str::ulid(), 'status' => $status, 'scan_status' => $quarantined ? 'pending' : 'infected',
            'scanned_at' => $quarantined ? null : now(), 'quarantine_key' => $quarantined ? 'quarantine/'.Str::ulid() : null,
            'original_filename' => 'a.pdf', 'size_bytes' => 10, 'title' => 'Synthetic upload', 'captured_at' => now(), 'uploaded_by' => $uploader->id,
            'classification' => 'internal', 'retention_until' => now('UTC')->addDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grantAll(User $user): void
    {
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => Classification::Internal->value,
            'granted_by' => $user->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
