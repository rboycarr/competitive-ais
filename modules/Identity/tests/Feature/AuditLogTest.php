<?php

namespace Modules\Identity\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Modules\Identity\Models\Team;
use Modules\Identity\Models\User;
use Modules\Identity\Tests\Support\CreatesUsers;
use Modules\Platform\Audit\Actor;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditEvent;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Classification;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationMatrix;
use Tests\TestCase;

/**
 * P1-16 / docs/07: holders of audit.view search the audit trail read-only; rows are filtered by AccessScope like any
 * record; network data is not shown; every search is audited.
 */
class AuditLogTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->app->make(AuditActions::class)->define('test.probe', ['n' => 'int']);
        $this->auditor = $this->createUser('auditor@example.test', 'auditor', clearance: Classification::Restricted);
    }

    public function test_only_audit_view_holders_open_the_log(): void
    {
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));

        foreach (['ci_analyst', 'exec_reader', 'data_collector'] as $role) {
            $this->actingAs($this->createUser("$role@example.test", $role))->get('/admin/audit')->assertForbidden();
        }
        foreach ([$this->auditor, $director, $admin] as $viewer) {
            $this->actingAs($viewer)->get('/admin/audit')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Identity/Admin/AuditLog'));
        }
        $this->get('/admin/audit')->assertOk(); // still signed in as the admin; the route has no write counterpart
        $this->actingAs($this->auditor)->post('/admin/audit')->assertStatus(405);
        $this->actingAs($this->auditor)->delete('/admin/audit')->assertStatus(405);
    }

    public function test_rows_follow_the_visibility_matrix(): void
    {
        // Audit rows are unscoped classified records: clearance, and a grant for restricted rows (docs/07 §3).
        $users = AuthorizationMatrix::users();
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] === [] && $r['markets'] === []));
        $ulids = [];
        foreach ($records as $i => $record) {
            $ulids[$record['label']] = $this->event(Classification::from($record['classification']), n: $i);
        }

        $visible = [];
        foreach ($users as $entry) {
            $visible[$entry['user']->id] = AuditEvent::query()->visibleTo($entry['user'])->where('action', 'test.probe')->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $visible[$user->id], true), 'AuditEvent::visibleTo()');

        // The page applies the same filter (active matrix users, given the auditor role for this).
        $viewers = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && in_array($u['grant'], ['none', 'all:restricted'], true)));
        $shown = [];
        foreach ($viewers as $entry) {
            $entry['user']->assignRole('auditor');
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/admin/audit?action=test.probe')->assertOk()->inertiaProps('events'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($viewers, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /admin/audit');
    }

    public function test_filters_narrow_the_results(): void
    {
        $other = $this->createUser('other@example.test', 'ci_analyst');
        $team = new Team;
        $team->forceFill(['ulid' => (string) Str::ulid(), 'name' => 'Pricing'])->save();

        $this->travelTo('2026-10-01 08:00:00');
        $old = $this->event(Classification::Internal, $other);
        $this->travelTo('2026-10-15 08:00:00');
        $onTeam = $this->event(Classification::Internal, $this->auditor, $team);
        $mine = $this->event(Classification::Internal, $this->auditor);
        $this->travelBack();

        $search = fn (array $query): array => array_column($this->actingAs($this->auditor)->get('/admin/audit?'.http_build_query($query))->assertOk()->inertiaProps('events'), 'ulid');

        $this->assertSame([$mine, $onTeam, $old], $search(['action' => 'test.probe']));
        $this->assertSame([$old], $search(['action' => 'test.probe', 'actor' => $other->ulid]));
        $this->assertSame([$onTeam], $search(['subject' => strtolower($team->ulid)]));
        $this->assertSame([$mine, $onTeam], $search(['action' => 'test.probe', 'from' => '2026-10-10T00:00:00Z']));
        $this->assertSame([$old], $search(['action' => 'test.probe', 'from' => '2026-09-30T23:00:00-02:00', 'to' => '2026-10-02T00:00:00Z'])); // offsets are honoured
        $this->assertSame([], $search(['actor' => (string) Str::ulid()]));

        $this->actingAs($this->auditor)->get('/admin/audit?subject=not-a-ulid')->assertSessionHasErrors('subject');
        $this->actingAs($this->auditor)->get('/admin/audit?action=no.such_action')->assertSessionHasErrors('action');
        $this->actingAs($this->auditor)->get('/admin/audit?from=2026-10-02T00:00:00Z&to=2026-10-01T00:00:00Z')->assertSessionHasErrors('to');
    }

    public function test_results_are_paged_newest_first(): void
    {
        $ulids = [];
        foreach (range(1, 55) as $n) {
            $ulids[] = $this->event(Classification::Internal, n: $n);
        }

        $first = $this->actingAs($this->auditor)->get('/admin/audit?action=test.probe')->assertOk();
        $this->assertSame(array_slice(array_reverse($ulids), 0, 50), array_column($first->inertiaProps('events'), 'ulid'));
        $this->assertNull($first->inertiaProps('previousUrl'));
        $next = (string) $first->inertiaProps('nextUrl');
        $this->assertStringContainsString('action=test.probe', $next);

        $second = $this->actingAs($this->auditor)->get($next)->assertOk();
        $this->assertSame(array_slice(array_reverse($ulids), 50), array_column($second->inertiaProps('events'), 'ulid'));
        $this->assertNull($second->inertiaProps('nextUrl'));
        $this->assertEquals(
            [['filters' => ['action'], 'next_page' => false], ['filters' => ['action'], 'next_page' => true]],
            DB::table('audit_events')->where('action', 'audit.searched')->orderBy('id')->pluck('context')->map(fn ($c) => json_decode((string) $c, true))->all(),
        );
    }

    public function test_forged_page_links_are_refused_not_errors(): void
    {
        // TASK-0027 #1: a cursor that decodes but lacks an integer id made the paginator throw (500).
        $encode = fn (array $fields): string => rtrim(strtr(base64_encode((string) json_encode($fields)), '+/', '-_'), '=');
        foreach (['forged', $encode(['_pointsToNextItems' => true]), $encode(['id' => 'x', '_pointsToNextItems' => true]),
            $encode(['id' => 5, 'occurred_at' => '2026-01-01', '_pointsToNextItems' => true]), str_repeat('A', 300)] as $cursor) {
            $this->actingAs($this->auditor)->get('/admin/audit?cursor='.urlencode($cursor))->assertRedirect()->assertSessionHasErrors('cursor');
        }
        // TASK-0028: array-shaped input is a validation error too (Cursor::fromEncoded returns null for non-strings).
        foreach (['cursor[]=x', 'cursor[id]=5&cursor[_pointsToNextItems]=1'] as $query) {
            $this->actingAs($this->auditor)->get("/admin/audit?$query")->assertRedirect()->assertSessionHasErrors('cursor');
        }
        $this->assertSame(0, DB::table('audit_events')->where('action', 'audit.searched')->count());
    }

    public function test_the_actor_list_needs_the_user_directory_and_inactive_viewers_are_signed_out(): void
    {
        // TASK-0027 #2: the actor filter lists every account (inactive too), so only for user.view holders.
        Role::create(['name' => 'audit_only', 'guard_name' => 'web'])->givePermissionTo('audit.view');
        $auditOnly = $this->createUser('audit-only@example.test', 'audit_only', clearance: Classification::Restricted);
        $this->createUser('gone@example.test', 'ci_analyst', 'deactivated');

        $this->actingAs($auditOnly)->get('/admin/audit')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('users', []));
        $this->actingAs($this->auditor)->get('/admin/audit')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('users', 3));

        DB::table('users')->where('id', $this->auditor->id)->update(['status' => 'suspended']);
        $this->actingAs($this->auditor->refresh())->get('/admin/audit')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_every_search_is_audited_and_network_data_stays_hidden(): void
    {
        $this->event(Classification::Internal);
        $response = $this->actingAs($this->auditor)->get('/admin/audit?action=test.probe&subject='.Str::ulid())->assertOk();

        $search = DB::table('audit_events')->where('action', 'audit.searched')->sole();
        $this->assertSame($this->auditor->id, $search->actor_id);
        $this->assertEquals(['filters' => ['action', 'subject'], 'next_page' => false], json_decode((string) $search->context, true));
        $this->assertSame('127.0.0.1', DB::table('audit_event_network')->where('audit_event_id', $search->id)->value('ip_address'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('127.0.0.1', $body);
        $this->assertStringNotContainsString('ip_address', $body);
        $this->assertStringNotContainsString('row_hash', $body);
    }

    public function test_the_read_model_cannot_change_audit_events(): void
    {
        $ulid = $this->event(Classification::Internal);
        $event = AuditEvent::query()->where('ulid', $ulid)->sole();

        foreach ([fn () => $event->forceFill(['action' => 'test.changed'])->save(), fn () => $event->delete()] as $change) {
            try {
                $change();
                $this->fail('An audit event was changed through the read model.');
            } catch (LogicException) {
                // expected
            }
        }
        $this->assertSame('test.probe', DB::table('audit_events')->where('ulid', $ulid)->value('action'));
    }

    private function event(Classification $classification, ?User $actor = null, ?Model $subject = null, int $n = 0): string
    {
        return DB::transaction(fn (): string => $this->app->make(AuditLogger::class)->record(
            'test.probe', $subject, ['n' => $n], $actor !== null ? Actor::user($actor->id) : Actor::system(), $classification,
        ));
    }
}
