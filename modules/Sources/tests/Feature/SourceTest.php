<?php

namespace Modules\Sources\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Platform\Support\Features;
use Modules\Sources\Actions\SourceService;
use Modules\Sources\Models\Source;
use RuntimeException;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-30 / docs/04 §2 / docs/02 §3.4: source.propose holders propose sources (manual only while the collection flag is
 * off), source.view holders read them within AccessScope; every proposal is audited; nothing is deleted.
 */
class SourceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['features.collection' => false]);
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
    }

    public function test_reading_needs_source_view_and_proposing_needs_source_propose(): void
    {
        $collector = $this->createUser('collector@example.test', 'data_collector'); // source.view only
        $reader = $this->createUser('reader@example.test', 'exec_reader'); // neither
        $admin = $this->enableTwoFactor($this->createUser('admin@example.test', 'super_admin', clearance: Classification::Restricted));
        $source = $this->source('Trade news');

        $this->actingAs($collector)->get('/sources')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Sources/Index')
            ->where('canPropose', false)->where('owners', [])->where('classifications', [])->where('sources.0.ulid', $source->ulid));
        $this->actingAs($collector)->get("/sources/{$source->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Sources/Show'));
        $this->actingAs($collector)->post('/sources', $this->form('Other'))->assertForbidden();
        $this->actingAs($reader)->get('/sources')->assertForbidden();
        $this->actingAs($reader)->get("/sources/{$source->ulid}")->assertForbidden();
        // Super admins manage the platform, not content (docs/07 ¹).
        $this->actingAs($admin)->get('/sources')->assertForbidden();
        $this->actingAs($this->analyst)->delete("/sources/{$source->ulid}")->assertStatus(405);
        $this->assertSame(['Trade news'], Source::query()->pluck('name')->all());

        // The service checks the actor itself.
        $this->expectException(AuthorizationException::class);
        app(SourceService::class)->propose($collector, ['name' => 'Direct call', 'source_type' => 'website', 'classification' => 'internal']);
    }

    public function test_an_analyst_proposes_a_source_and_it_is_audited(): void
    {
        $response = $this->actingAs($this->analyst)->post('/sources', $this->form('  Trade   News  ', [
            'source_type' => 'website', 'base_url' => 'https://News.Example./market', 'default_reliability' => 'B',
            'description' => 'Synthetic test entry.',
        ]))->assertSessionHasNoErrors();

        $source = Source::query()->sole();
        $response->assertRedirect("/sources/{$source->ulid}");
        $this->assertSame(
            ['Trade News', 'website', 'https://News.Example./market', 'news.example', 'manual', 'proposed', 'B', $this->analyst->id, $this->analyst->id, Classification::Internal, 'Synthetic test entry.'],
            [$source->name, $source->source_type, $source->base_url, $source->host, $source->method, $source->status, $source->default_reliability, $source->owner_id, $source->proposed_by, $source->classification, $source->description],
        );

        $audit = DB::table('audit_events')->where('action', 'source.proposed')->sole();
        $this->assertSame([$this->analyst->id, $source->ulid, 'internal'], [$audit->actor_id, $audit->subject_ulid, $audit->classification]);
        $this->assertEquals(['source_type' => 'website', 'method' => 'manual', 'classification' => 'internal'], json_decode((string) $audit->context, true));
        $this->assertSame(0, DB::table('outbox_events')->count()); // events come with their first consumer

        // Defaults: no address, manual, reliability F (cannot be judged). Domains are stored in lower case; internationalized
        // names are entered in punycode (the shared web-address rule).
        $this->actingAs($this->analyst)->post('/sources', ['name' => 'Field visits', 'source_type' => 'field', 'classification' => 'internal'])->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/sources', $this->form('Bücher', ['base_url' => 'HTTPS://XN--BCHER-KVA.EXAMPLE/']))->assertSessionHasNoErrors();
        $this->actingAs($this->analyst)->post('/sources', $this->form('Unicode', ['base_url' => 'https://bücher.example/']))->assertSessionHasErrors('base_url');
        $this->assertSame([[null, 'manual', 'F'], ['xn--bcher-kva.example', 'manual', 'F']],
            Source::query()->whereIn('name', ['Field visits', 'Bücher'])->orderBy('id')->get()->map(fn (Source $s): array => [$s->host, $s->method, $s->default_reliability])->all());
    }

    public function test_only_manual_collection_while_the_collection_flag_is_off(): void
    {
        foreach (['rss', 'api', 'http_fetch', 'browser'] as $method) {
            $this->actingAs($this->analyst)->post('/sources', $this->form("Feed $method", ['method' => $method, 'base_url' => 'https://feeds.example/']))
                ->assertSessionHasErrors(['method' => 'Only manual collection is available while automated collection is switched off.']);
        }
        $this->actingAs($this->analyst)->get('/sources')->assertInertia(fn (AssertableInertia $page) => $page->where('methods', ['manual']));

        // Not even a direct call gets past it, and anything but exactly true is off.
        foreach ([false, null, 'true', 'false', 1, 0, '1'] as $value) {
            config(['features.collection' => $value]);
            try {
                app(SourceService::class)->propose($this->analyst, ['name' => 'Direct', 'source_type' => 'rss', 'method' => 'rss', 'base_url' => 'https://feeds.example/', 'classification' => 'internal']);
                $this->fail('An automated method was accepted with the flag set to '.json_encode($value));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('method', $e->errors());
            }
        }
        $this->assertSame(0, Source::query()->count());

        // With the flag on (Phase 3), automated methods are accepted, and need an address.
        config(['features.collection' => true]);
        $this->actingAs($this->analyst)->post('/sources', $this->form('Feed', ['method' => 'rss']))->assertSessionHasErrors(['base_url' => 'Automated collection needs a base URL.']);
        $this->actingAs($this->analyst)->post('/sources', $this->form('Feed', ['method' => 'rss', 'base_url' => 'https://feeds.example/rss']))->assertSessionHasNoErrors();
        $this->assertSame('rss', Source::query()->sole()->method);
        $this->actingAs($this->analyst)->get('/sources')->assertInertia(fn (AssertableInertia $page) => $page->where('methods', Source::METHODS));

        $this->expectException(InvalidArgumentException::class);
        app(Features::class)->enabled('colection');
    }

    public function test_invalid_proposals_are_refused(): void
    {
        $gone = $this->createUser('gone@example.test', 'ci_analyst', 'suspended');
        $collector = $this->createUser('collector@example.test', 'data_collector');
        $internalOnly = $this->createUser('internal@example.test', 'ci_analyst');

        foreach ([
            'name' => ['name' => '   '],
            'source_type' => ['source_type' => 'darkweb'],
            'method' => ['method' => 'scraper'],
            'default_reliability' => ['default_reliability' => 'b'],
            'classification' => ['classification' => 'secret'],
            'owner' => ['owner' => 'not-a-ulid'],
        ] as $field => $overrides) {
            $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', $overrides))->assertSessionHasErrors($field);
        }
        foreach ([
            'javascript:alert(1)' => 'The base URL must be an http:// or https:// address.',
            'ftp://files.example/' => 'The base URL must be an http:// or https:// address.',
            'https://user:secret@news.example/' => 'The base URL must not contain a user name or password.',
            'https://token@news.example/' => 'The base URL must not contain a user name or password.',
            'http://127.0.0.1/' => 'The base URL must use a domain name, not an IP address or a local name.',
            'http://[::1]/' => 'The base URL must use a domain name, not an IP address or a local name.',
            'http://localhost/' => 'The base URL must use a domain name, not an IP address or a local name.',
            'http://10.0.0.1.:8080/' => 'The base URL must use a domain name, not an IP address or a local name.',
            'https://api.example/feed?api_key=secret' => 'The base URL must not contain a query string or fragment: never put keys or tokens in it.',
            'https://api.example/feed?' => 'The base URL must not contain a query string or fragment: never put keys or tokens in it.',
            'https://api.example/feed#token=secret' => 'The base URL must not contain a query string or fragment: never put keys or tokens in it.',
        ] as $url => $message) {
            $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', ['base_url' => $url]))->assertSessionHasErrors(['base_url' => $message]);
        }
        // Owners: active source.propose holders who can see the source.
        $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', ['owner' => $gone->ulid]))->assertSessionHasErrors('owner');
        $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', ['owner' => $collector->ulid]))->assertSessionHasErrors('owner');
        $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', ['owner' => $internalOnly->ulid, 'classification' => 'confidential']))
            ->assertSessionHasErrors(['owner' => 'The owner must be able to see a source with this classification.']);
        // Nobody classifies above what they can see.
        $this->actingAs($internalOnly)->post('/sources', $this->form('Bad', ['classification' => 'confidential']))->assertSessionHasErrors('classification');
        $this->actingAs($this->analyst)->post('/sources', $this->form('Bad', ['classification' => 'restricted']))->assertSessionHasErrors('classification');
        $this->assertSame(0, Source::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'source.proposed')->count());

        // A valid other owner, in either ULID case.
        $this->actingAs($this->analyst)->post('/sources', $this->form('Good', ['owner' => strtolower($internalOnly->ulid)]))->assertSessionHasNoErrors();
        $this->assertSame($internalOnly->id, Source::query()->sole()->owner_id);
    }

    public function test_visibility_follows_the_matrix_for_lists_and_details(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: source.view
        $records = array_values(array_filter(AuthorizationMatrix::records(), fn (array $r): bool => $r['competitors'] === [] && $r['markets'] === []));
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->source('Source '.$record['label'], Classification::from($record['classification']))->ulid;
        }

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = Source::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'Source::visibleTo()');

        $active = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && in_array($u['grant'], ['none', 'all:restricted'], true)));
        AuthorizationMatrix::assertMatrix($active, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->get("/sources/{$ulids[$record['label']]}")->getStatusCode();
            $this->assertContains($status, [200, 404]);

            return $status === 200;
        }, 'GET /sources/{ulid}');
        $shown = [];
        foreach ($active as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/sources')->inertiaProps('sources'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($active, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /sources');
    }

    public function test_the_list_filters_by_status_and_type(): void
    {
        $news = $this->source('Trade news');
        $field = $this->source('Field visits', type: 'field', status: 'approved');

        $this->actingAs($this->analyst)->get('/sources?status=approved')->assertInertia(fn (AssertableInertia $page) => $page->has('sources', 1)->where('sources.0.ulid', $field->ulid));
        $this->actingAs($this->analyst)->get('/sources?type=website')->assertInertia(fn (AssertableInertia $page) => $page->has('sources', 1)->where('sources.0.ulid', $news->ulid));
        $this->actingAs($this->analyst)->get('/sources?status=gone')->assertSessionHasErrors('status');
    }

    public function test_the_database_holds_the_source_rules(): void
    {
        $row = fn (array $overrides): array => $overrides + [
            'ulid' => (string) Str::ulid(), 'name' => 'Probe', 'source_type' => 'website', 'base_url' => 'https://news.example/', 'host' => 'news.example',
            'method' => 'manual', 'status' => 'proposed', 'default_reliability' => 'F', 'owner_id' => $this->analyst->id, 'proposed_by' => $this->analyst->id,
            'classification' => 'internal', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([
            'type' => ['source_type' => 'darkweb'],
            'method' => ['method' => 'scraper'],
            'status' => ['status' => 'rejected'],
            'reliability' => ['default_reliability' => 'f'],
            'classification' => ['classification' => 'secret'],
            'name' => ['name' => '  '],
            'base_url' => ['base_url' => 'javascript:alert(1)'],
            'host' => ['host' => null],
            'collection_url' => ['method' => 'rss', 'base_url' => null, 'host' => null],
        ] as $check => $overrides) {
            try {
                DB::table('sources')->insert($row($overrides));
                $this->fail("The database accepted a source breaking sources_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("sources_{$check}_check", $e->getMessage());
            }
        }
        // The same URL and host rules as the service (TASK-0050 #2).
        foreach ([
            'base_url' => ['https://user:pass@news.example/', 'https://token@news.example/', 'https://news.example/feed?api_key=secret', 'https://news.example/#token', 'https://news.example?x'],
            'host' => ['News.Example', '-news.example', 'news-.example', 'localhost', 'news..example', '.news.example', 'news.example.', '10.0.0.1'],
        ] as $check => $values) {
            foreach ($values as $value) {
                $probe = $check === 'host' ? ['host' => $value, 'base_url' => "https://$value/"] : ['base_url' => $value];
                try {
                    DB::table('sources')->insert($row($probe));
                    $this->fail("The database accepted the $check $value.");
                } catch (QueryException $e) {
                    $this->assertMatchesRegularExpression('/sources_(base_url|host|host_match)_check/', $e->getMessage(), "$check $value");
                }
            }
        }
        foreach ([['https://other.example/', 'news.example'], ['https://news.example.evil.example/', 'news.example'], ['https://xnews.example/', 'news.example'], ['https://news.example', 'ews.example']] as [$url, $host]) {
            try {
                DB::table('sources')->insert($row(['base_url' => $url, 'host' => $host]));
                $this->fail("The database accepted host $host for $url.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('sources_host_match_check', $e->getMessage());
            }
        }
        // What the service stores passes: upper-case address, trailing dot, port, punycode.
        foreach ([['HTTPS://News.Example./market', 'news.example'], ['https://news.example:8443', 'news.example'], ['https://xn--bcher-kva.example/a/b', 'xn--bcher-kva.example']] as [$url, $host]) {
            DB::table('sources')->insert($row(['base_url' => $url, 'host' => $host]));
        }

        // The app account cannot delete sources (grant class no_delete).
        DB::table('sources')->insert($row([]));
        try {
            DB::table('sources')->delete();
            $this->fail('The app account deleted a source.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('DELETE command denied', $e->getMessage());
        }
    }

    public function test_the_rollback_refuses_while_sources_exist(): void
    {
        $this->source('Trade news');
        $migration = require base_path('modules/Sources/database/migrations/2026_11_14_000100_create_sources_table.php');

        try {
            $migration->down();
            $this->fail('The rollback dropped the sources table although sources exist.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Sources exist', $e->getMessage());
        }
        $this->assertSame(1, Source::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(string $name, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'source_type' => 'website', 'classification' => 'internal'];
    }

    private function source(string $name, Classification $classification = Classification::Internal, string $type = 'website', string $status = 'proposed'): Source
    {
        $source = new Source;
        $source->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'source_type' => $type, 'method' => 'manual', 'status' => $status, 'default_reliability' => 'F',
            'owner_id' => $this->analyst->id, 'proposed_by' => $this->analyst->id, 'classification' => $classification,
        ])->save();

        return $source;
    }
}
