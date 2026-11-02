<?php

namespace Modules\Sources\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Classification;
use Modules\Platform\Support\Features;
use Modules\Sources\Models\Source;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-30 against the real flag store (TASK-0054 #3): the collection flag as stored in the `features` table and switched
 * by the audited command decides which collection methods a proposal may use. SourceTest covers the value rules with
 * Pennant's in-memory store; switches commit on the migrator account, so this class uses DatabaseMigrations.
 */
class SourceCollectionFlagTest extends TestCase
{
    use CreatesUsers, DatabaseMigrations;

    protected function tearDown(): void
    {
        // The rollbacks after each test refuse while a flag is on or a source exists.
        DB::connection('migrator')->table('features')->update(['value' => 'false']);
        DB::connection('migrator')->table('sources')->delete();

        parent::tearDown();
    }

    public function test_the_stored_collection_flag_decides_the_methods(): void
    {
        $this->withoutVite();
        $analyst = $this->createUser('analyst@example.test', 'ci_analyst', clearance: Classification::Confidential);
        $feed = ['name' => 'Feed', 'source_type' => 'rss', 'method' => 'rss', 'base_url' => 'https://feeds.example/rss', 'classification' => 'internal'];

        $this->actingAs($analyst)->post('/sources', $feed)
            ->assertSessionHasErrors(['method' => 'Only manual collection is available while automated collection is switched off.']);

        $this->assertSame(0, Artisan::call('feature:switch', ['flag' => 'collection', 'state' => 'on']));
        $this->actingAs($analyst)->get('/sources')->assertInertia(fn (AssertableInertia $page) => $page->where('methods', Source::METHODS));
        $this->actingAs($analyst)->post('/sources', $feed)->assertSessionHasNoErrors();

        // Changed without the audited command: read as off.
        DB::connection('migrator')->table('features')->update(['value' => 'false']);
        $this->assertSame(0, Artisan::call('feature:switch', ['flag' => 'collection', 'state' => 'off']));
        DB::connection('migrator')->table('features')->update(['value' => 'true']);
        $this->actingAs($analyst)->post('/sources', ['name' => 'Feed 2'] + $feed)
            ->assertSessionHasErrors(['method' => 'Only manual collection is available while automated collection is switched off.']);

        $this->assertSame(['rss'], Source::query()->pluck('method')->all());
    }
}
