<?php

namespace Modules\Intelligence\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Evidence\Actions\EvidenceScanService;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Models\ClaimEvidenceLink;
use Modules\Intelligence\Models\IntelligenceClaim;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use Modules\Registry\Actions\CompetitorService;
use Modules\Registry\Models\Competitor;
use RuntimeException;
use Tests\Support\Concurrently;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Intelligence rules under truly parallel requests and with committed data (TASK-0084, moved into P1-41):
 * - claim moves and evidence links on one item, all from the same version: the item's row lock and version check let
 *   exactly one through, and positions stay a permutation;
 * - creating an item about a competitor and archiving that competitor are ordered by the competitor's row (share lock
 *   versus FOR UPDATE), whichever comes first: never an open item about an archived competitor;
 * - the Intelligence migrations refuse to roll back while items or links exist, cited evidence cannot be removed, and
 *   both roll back and re-apply once empty.
 * DatabaseMigrations, so tearDown removes the rows (the app account cannot, and the migrations refuse while they exist).
 */
class IntelligenceRaceTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    private const ROUNDS = 3;

    private const ITEMS = 'modules/Intelligence/database/migrations/2026_11_24_000100_create_intelligence_tables.php';

    private const LINKS = 'modules/Intelligence/database/migrations/2026_11_25_000100_create_claim_evidence_links_table.php';

    private User $analyst;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        foreach ([$this->analyst, $this->director] as $user) {
            DB::table('access_grants')->insert([
                'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
                'granted_by' => $this->director->id, 'reason' => 'Synthetic test grant', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('intelligence_items')) {
            $migrator = DB::connection('migrator');
            $migrator->table('intelligence_items')->delete(); // cascades to subjects, claims and their links
            $migrator->table('evidence_custody_events')->delete();
            $migrator->table('evidence_assets')->delete();
            $migrator->table('evidence_blobs')->delete();
            DB::disconnect('migrator');
        }

        parent::tearDown();
    }

    public function test_concurrent_claim_moves_and_links_from_one_version_have_one_winner(): void
    {
        $competitor = $this->competitor('Acme');
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $item = $this->item($competitor);
            $claims = array_map(fn (int $n) => app(IntelligenceClaimService::class)->add($this->analyst, $item, [
                'statement' => "Claim $n", 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version,
            ]), [1, 2, 3]);
            $version = $item->version;
            $move = fn (IntelligenceClaim $claim, string $direction) => fn () => app(IntelligenceClaimService::class)
                ->move($this->analyst, $item, $claim->ulid, ['direction' => $direction, 'version' => $version]);

            $results = Concurrently::run([$move($claims[1], 'up'), $move($claims[2], 'up'), $move($claims[0], 'down'), $move($claims[1], 'down')]);
            $this->assertOneWinner($results, "moves, round $round");
            $this->assertSame([1, 2, 3], $item->claims()->orderBy('position')->pluck('position')->all());
            $this->assertCount(3, array_unique($item->claims()->orderBy('position')->pluck('ulid')->all()), 'every claim keeps one place');
            $this->assertSame($version + 1, $item->refresh()->version);

            $asset = $this->available();
            $version = $item->version;
            $results = Concurrently::run(array_map(fn (string $relation) => fn () => app(IntelligenceClaimService::class)
                ->link($this->analyst, $item, $claims[0]->ulid, ['evidence' => $asset, 'relation' => $relation, 'version' => $version]), ['supports', 'contradicts', 'context', 'supports']));
            $this->assertOneWinner($results, "links, round $round");
            $this->assertSame(1, ClaimEvidenceLink::query()->where('intelligence_claim_id', $claims[0]->id)->count());
            $this->assertSame(1, DB::table('evidence_custody_events')->where('event', 'linked')->whereJsonContains('details->item', $item->ulid)->count());
            $this->assertSame($version + 1, $item->refresh()->version);
        }
        $this->assertSame(self::ROUNDS, DB::table('audit_events')->where('action', 'intelligence.claim_moved')->count());
    }

    /** The item's transaction holds the competitor's share lock while the archive waits for it, and the reverse. */
    public function test_creating_an_item_and_archiving_its_competitor_are_ordered_by_the_competitor_row(): void
    {
        $signal = sys_get_temp_dir().'/cais-p141-'.bin2hex(random_bytes(6));

        // The item first: its transaction stalls after the share lock; the archive then waits, and finds an open item.
        $first = $this->competitor('Acme');
        $results = $this->race(
            function () use ($first, $signal): void {
                IntelligenceItem::created(function () use ($signal): void {
                    touch($signal);
                    usleep(1_500_000);
                });
                $this->item($first);
            },
            fn () => app(CompetitorService::class)->setLifecycle($this->director, $first, 'archived'),
            $signal,
        );
        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('active', $first->refresh()->lifecycle);
        $this->assertSame(1, IntelligenceItem::query()->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $first->id))->count());

        // The archive first: it stalls holding the row; the item then waits for its share lock, and finds it archived.
        $second = $this->competitor('Globex');
        $results = $this->race(
            function () use ($second, $signal): void {
                Competitor::saving(function () use ($signal): void {
                    touch($signal);
                    usleep(1_500_000);
                });
                app(CompetitorService::class)->setLifecycle($this->director, $second, 'archived');
            },
            fn () => $this->item($second),
            $signal,
        );
        $this->assertSame(['ok', ValidationException::class], array_column($results, 'outcome'), json_encode($results) ?: '');
        $this->assertSame('archived', $second->refresh()->lifecycle);
        $this->assertSame(0, IntelligenceItem::query()->whereHas('competitorSubjects', fn ($q) => $q->where('competitor_id', $second->id))->count());
    }

    public function test_the_migrations_refuse_to_roll_back_while_intelligence_exists(): void
    {
        $tables = fn (): array => [Schema::hasTable('claim_evidence_links'), Schema::hasTable('intelligence_items')];
        $item = $this->item($this->competitor('Acme'));
        $claim = app(IntelligenceClaimService::class)->add($this->analyst, $item, [
            'statement' => 'Claim', 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => 1,
        ]);
        $asset = $this->available();
        app(IntelligenceClaimService::class)->link($this->analyst, $item, $claim->ulid, ['evidence' => $asset, 'relation' => 'supports', 'version' => 2]);

        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::LINKS, '--force' => true]), RuntimeException::class, 'Claim evidence links exist');
        $this->assertThrows(fn () => Artisan::call('migrate:rollback', ['--path' => self::ITEMS, '--force' => true]), RuntimeException::class, 'Intelligence items exist');
        $this->assertSame([true, true], $tables());
        $this->assertSame(1, ClaimEvidenceLink::query()->count());
        // Cited evidence cannot be removed, even by the migrator.
        try {
            DB::connection('migrator')->table('evidence_assets')->where('ulid', $asset)->delete();
            $this->fail('Cited evidence was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('claim_evidence_links', $e->getMessage());
        }

        DB::connection('migrator')->table('intelligence_items')->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::LINKS, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::ITEMS, '--force' => true]));
        $this->assertSame([false, false], $tables());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::ITEMS, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::LINKS, '--force' => true]));
        $this->assertSame([true, true], $tables());
    }

    /**
     * Runs $stalling, which signals from inside its transaction and then holds it, against $waiting, started once the
     * signal is seen.
     *
     * @return list<array{outcome: string, message: string}>
     */
    private function race(callable $stalling, callable $waiting, string $signal): array
    {
        try {
            return Concurrently::run([$stalling, function () use ($waiting, $signal): void {
                $deadline = microtime(true) + 30;
                while (! is_file($signal)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('The first transaction never started.');
                    }
                    usleep(20_000);
                }
                $waiting();
            }], 60.0);
        } finally {
            if (file_exists($signal)) {
                unlink($signal);
            }
        }
    }

    private function item(Competitor $competitor): IntelligenceItem
    {
        return app(IntelligenceItemService::class)->create($this->analyst, [
            'title' => 'Widget launch', 'summary' => '', 'item_type' => 'news', 'classification' => 'internal', 'competitors' => [$competitor->ulid],
        ]);
    }

    private function competitor(string $name): Competitor
    {
        $id = (int) DB::table('competitors')->insertGetId([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $this->analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Competitor::query()->findOrFail($id);
    }

    /** An available asset's ULID. */
    private function available(): string
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', (string) Str::ulid()), 'storage_disk' => EvidenceScanService::EVIDENCE_DISK, 'storage_key' => 'sha256/'.Str::random(60),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $ulid = (string) Str::ulid();
        DB::table('evidence_assets')->insert([
            'ulid' => $ulid, 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob,
            'original_filename' => 'report.pdf', 'size_bytes' => 10, 'title' => 'Synthetic evidence', 'captured_at' => now()->subDay(),
            'uploaded_by' => $this->analyst->id, 'classification' => 'internal', 'retention_until' => now('UTC')->addDays(30)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ulid;
    }

    /** @param  list<array{outcome: string, message: string}>  $results */
    private function assertOneWinner(array $results, string $scenario): void
    {
        foreach ($results as $i => $result) {
            $this->assertContains($result['outcome'], ['ok', ValidationException::class], "$scenario, request $i: {$result['outcome']} {$result['message']}");
        }
        $this->assertSame(1, count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok')), "$scenario: ".json_encode($results));
    }
}
