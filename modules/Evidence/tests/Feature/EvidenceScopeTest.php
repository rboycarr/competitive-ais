<?php

namespace Modules\Evidence\Tests\Feature;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Evidence\Actions\EvidenceHoldService;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Evidence\Models\EvidenceAssetCompetitor;
use Modules\Evidence\Models\EvidenceAssetMarket;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use RuntimeException;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-36a / docs/07 §3 / docs/02 §3.5: evidence is about the competitors and markets its uploader declares, and only
 * viewers with grants covering every one of them see it (all-of): in the list, on the detail page, for download and
 * for legal hold, the same predicate as every other module (the shared authorization matrix). Scopes are declared at
 * upload, only ever added (the app account cannot change or remove one), and nobody uploads evidence they could not
 * read back.
 */
class EvidenceScopeTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake(EvidenceUploadService::QUARANTINE_DISK);
        Storage::fake(EvidenceScanService::EVIDENCE_DISK);
        Storage::disk(EvidenceScanService::EVIDENCE_DISK)->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expires, array $options): string => 'https://files.example.test/'.$path,
        );
        Queue::fake();
    }

    public function test_visibility_of_scoped_evidence_follows_the_matrix_everywhere(): void
    {
        $users = AuthorizationMatrix::users(); // ci_analyst: evidence.view, evidence.download
        $records = AuthorizationMatrix::records();
        $uploader = $this->createUser('uploader@example.test', 'data_collector', clearance: Classification::Restricted);
        $ulids = [];
        foreach ($records as $record) {
            $ulids[$record['label']] = $this->available('Evidence '.$record['label'], Classification::from($record['classification']), $uploader, $record['competitors'], $record['markets'])->ulid;
        }

        $lists = [];
        foreach ($users as $entry) {
            $lists[$entry['user']->id] = EvidenceAsset::query()->visibleTo($entry['user'])->pluck('ulid')->all();
        }
        AuthorizationMatrix::assertMatrix($users, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $lists[$user->id], true), 'EvidenceAsset::visibleTo()');

        // Every scoped grant shape, over HTTP: list, detail and download.
        $active = array_values(array_filter($users, fn (array $u): bool => $u['status'] === 'active' && $u['clearance'] === 'restricted'));
        $shown = [];
        foreach ($active as $entry) {
            $shown[$entry['user']->id] = array_column($this->actingAs($entry['user'])->get('/evidence')->inertiaProps('assets'), 'ulid');
        }
        AuthorizationMatrix::assertMatrix($active, $records, fn (User $user, array $record): bool => in_array($ulids[$record['label']], $shown[$user->id], true), 'GET /evidence');
        AuthorizationMatrix::assertMatrix($active, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->get("/evidence/{$ulids[$record['label']]}")->getStatusCode();
            $this->assertContains($status, [200, 404]);

            return $status === 200;
        }, 'GET /evidence/{ulid}');
        $this->withoutMiddleware(ThrottleRequests::class); // more downloads than the per-user limit; that limit has its own test
        AuthorizationMatrix::assertMatrix($active, $records, function (User $user, array $record) use ($ulids): bool {
            $status = $this->actingAs($user)->post("/evidence/{$ulids[$record['label']]}/download")->getStatusCode();
            $this->assertContains($status, [302, 404]);

            return $status === 302;
        }, 'POST /evidence/{ulid}/download');
    }

    public function test_a_director_without_a_covering_grant_cannot_hold_scoped_evidence(): void
    {
        AuthorizationMatrix::users();
        $uploader = $this->createUser('uploader@example.test', 'data_collector', clearance: Classification::Restricted);
        $asset = $this->available('About A', Classification::Internal, $uploader, [AuthorizationMatrix::competitorId('A')], []);
        $director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Restricted));

        $this->actingAs($director)->post("/evidence/{$asset->ulid}/hold", ['hold' => true, 'reason' => 'Notice'])->assertNotFound();
        $this->grant($director, 'competitor', AuthorizationMatrix::competitorId('A'), null, 'internal');
        $this->app->forgetScopedInstances(); // grants are cached per request; this is a new one
        app(EvidenceHoldService::class)->change($director, $asset, true, 'Notice');
        $this->assertTrue((bool) $asset->fresh()?->legal_hold);
    }

    public function test_an_upload_declares_its_scopes_and_its_uploader_can_read_it_back(): void
    {
        AuthorizationMatrix::users();
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grant($analyst, 'competitor', AuthorizationMatrix::competitorId('A'), null, 'confidential');
        $this->grant($analyst, 'market', null, AuthorizationMatrix::marketId('M'), 'confidential');
        [$a, $m] = [$this->ulidOf('competitors', AuthorizationMatrix::competitorId('A')), $this->ulidOf('markets', AuthorizationMatrix::marketId('M'))];

        $this->actingAs($analyst)->get('/evidence')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('competitors', fn ($options): bool => in_array($a, array_column($options->all(), 'value'), true))
            ->where('markets', fn ($options): bool => in_array($m, array_column($options->all(), 'value'), true)));

        $this->actingAs($analyst)->post('/evidence', [
            'file' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF), 'title' => 'About A in M', 'captured_at' => '2026-11-01T10:00:00Z',
            'classification' => 'internal', 'competitors' => [$a, $a], 'markets' => [$m],
        ])->assertSessionHasNoErrors();

        $asset = EvidenceAsset::query()->sole();
        $this->assertSame([[AuthorizationMatrix::competitorId('A')], [AuthorizationMatrix::marketId('M')]], [$asset->competitorIds(), $asset->marketIds()]);
        $scope = EvidenceAssetCompetitor::query()->sole();
        $this->assertSame(['declared', $analyst->id], [$scope->origin, $scope->added_by]);
        $this->actingAs($analyst)->get("/evidence/{$asset->ulid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('asset.competitors', ['Matrix competitor A'])->where('asset.markets', ['Matrix market M (MATRIX-M)']));

        // Another analyst of the same clearance, without the grants, does not see it.
        $other = $this->createUser('other@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->actingAs($other)->get("/evidence/{$asset->ulid}")->assertNotFound();
    }

    public function test_scopes_the_uploader_cannot_see_or_does_not_hold_are_refused_and_nothing_is_stored(): void
    {
        AuthorizationMatrix::users();
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->grant($analyst, 'competitor', AuthorizationMatrix::competitorId('A'), null, 'internal');
        $hidden = DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => 'Restricted rival', 'slug' => 'restricted-rival', 'lifecycle' => 'active',
            'classification' => 'restricted', 'created_by' => $analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $this->ulidOf('competitors', AuthorizationMatrix::competitorId('A'));
        $b = $this->ulidOf('competitors', AuthorizationMatrix::competitorId('B'));
        $uploads = app(EvidenceUploadService::class);

        $cases = [
            'unknown competitor' => ['competitors', ['competitors' => [(string) Str::ulid()]]],
            'competitor above clearance' => ['competitors', ['competitors' => [$this->ulidOf('competitors', $hidden)]]],
            'not a ULID' => ['competitors', ['competitors' => ['../../etc']]],
            'not a list' => ['competitors', ['competitors' => 'nope']],
            'unknown market' => ['markets', ['markets' => [(string) Str::ulid()]]],
            'too many' => ['markets', ['markets' => array_fill(0, EvidenceUploadService::MAX_SCOPES + 1, (string) Str::ulid())]],
            'competitor without a grant' => ['scopes', ['competitors' => [$b]]],
            'grant below the classification' => ['scopes', ['competitors' => [$a], 'classification' => 'confidential']],
        ];
        foreach ($cases as $case => [$field, $override]) {
            try {
                $uploads->upload($analyst, UploadedFile::fake()->createWithContent('a.pdf', self::PDF), $override + ['title' => 'Scoped', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal']);
                $this->fail("Accepted: $case");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $case);
            }
        }
        $this->assertSame(0, EvidenceAsset::query()->count());
        $this->assertSame([], Storage::disk(EvidenceUploadService::QUARANTINE_DISK)->allFiles());

        $uploads->upload($analyst, UploadedFile::fake()->createWithContent('a.pdf', self::PDF), ['competitors' => [$a], 'title' => 'Scoped', 'captured_at' => '2026-11-01T10:00:00Z', 'classification' => 'internal']);
        $this->assertSame(1, EvidenceAssetCompetitor::query()->count());
    }

    public function test_the_database_holds_the_scope_rules_and_rollback_is_refused_while_scopes_exist(): void
    {
        AuthorizationMatrix::users();
        $uploader = $this->createUser('uploader@example.test', 'data_collector', clearance: Classification::Restricted);
        $asset = $this->available('About A', Classification::Internal, $uploader, [AuthorizationMatrix::competitorId('A')], [AuthorizationMatrix::marketId('M')]);
        $row = fn (array $overrides = []): array => $overrides + ['evidence_asset_id' => $asset->id, 'competitor_id' => AuthorizationMatrix::competitorId('B'), 'origin' => 'declared', 'added_by' => $uploader->id, 'created_at' => now()];

        $this->assertDatabaseError(3819, fn () => DB::table('evidence_asset_competitor')->insert($row(['origin' => 'guessed'])), 'unknown origin');
        $this->assertDatabaseError(1062, fn () => DB::table('evidence_asset_competitor')->insert($row(['competitor_id' => AuthorizationMatrix::competitorId('A')])), 'duplicate scope');
        $this->assertDatabaseError(1452, fn () => DB::table('evidence_asset_competitor')->insert($row(['competitor_id' => 999999])), 'unknown competitor');
        // Append-only for the app account: a scope is never changed or removed (that would widen visibility).
        foreach (['evidence_asset_competitor', 'evidence_asset_market'] as $table) {
            $this->assertDatabaseError(1142, fn () => DB::table($table)->where('evidence_asset_id', $asset->id)->delete(), "$table delete");
            $this->assertDatabaseError(1142, fn () => DB::table($table)->where('evidence_asset_id', $asset->id)->update(['origin' => 'inherited']), "$table update");
        }
        $this->assertSame([1, 1], [EvidenceAssetCompetitor::query()->count(), EvidenceAssetMarket::query()->count()]);

        $migration = require base_path('modules/Evidence/database/migrations/2026_11_21_000100_create_evidence_scope_tables.php');
        $this->assertThrows(fn () => $migration->down(), RuntimeException::class, 'widen');
    }

    /**
     * @param  list<int>  $competitors
     * @param  list<int>  $markets
     */
    private function available(string $title, Classification $classification, User $uploader, array $competitors, array $markets): EvidenceAsset
    {
        $key = 'sha256/'.Str::random(60);
        $blobId = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => $key,
            'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd', 'first_scanned_at' => now(),
            'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blobId,
            'original_filename' => 'a.pdf', 'size_bytes' => 10, 'title' => $title, 'captured_at' => now(), 'uploaded_by' => $uploader->id,
            'classification' => $classification,
        ])->save();
        foreach ([['evidence_asset_competitor', 'competitor_id', $competitors], ['evidence_asset_market', 'market_id', $markets]] as [$table, $column, $ids]) {
            foreach ($ids as $id) {
                DB::table($table)->insert(['evidence_asset_id' => $asset->id, $column => $id, 'origin' => 'declared', 'added_by' => $uploader->id, 'created_at' => now()]);
            }
        }

        return $asset;
    }

    private function grant(User $user, string $scope, ?int $competitorId, ?int $marketId, string $level): void
    {
        $grantor = $this->createUser('grantor-'.Str::lower(Str::random(6)).'@example.test', 'ci_analyst', clearance: Classification::Restricted);
        DB::table('access_grants')->insert([
            'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => $scope, 'competitor_id' => $competitorId, 'market_id' => $marketId,
            'max_classification' => $level, 'granted_by' => $grantor->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ulidOf(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('ulid');
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
