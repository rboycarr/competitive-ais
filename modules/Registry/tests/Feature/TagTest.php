<?php

namespace Modules\Registry\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Identity\Models\User;
use Modules\Platform\Classification;
use Modules\Registry\Models\Competitor;
use Modules\Registry\Models\Tag;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * P1-23b / docs/02 §3.3 / docs/03 Registry: tags within a taxonomy, maintained by taxonomy.manage holders; competitor
 * managers tag the competitors they can see; the competitor list filters by tag. A tag in use is kept (service and
 * foreign key); a competitor link always points at an existing competitor (generated column with a foreign key).
 */
class TagTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $director;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->director = $this->enableTwoFactor($this->createUser('director@example.test', 'ci_director', clearance: Classification::Confidential));
        $this->analyst = $this->createUser('analyst@example.test', 'ci_analyst');
    }

    public function test_tags_are_managed_by_taxonomy_managers_and_applied_by_competitor_managers(): void
    {
        $reader = $this->createUser('reader@example.test', 'exec_reader');
        $tag = $this->tag('segment', 'Premium');
        $competitor = $this->competitor('Acme Foods');

        $this->actingAs($this->analyst)->get('/admin/tags')->assertForbidden();
        $this->actingAs($this->analyst)->post('/admin/tags', ['taxonomy' => 'segment', 'name' => 'Value'])->assertForbidden();
        $this->actingAs($this->analyst)->delete("/admin/tags/{$tag->id}")->assertForbidden();
        $this->actingAs($reader)->post("/competitors/{$competitor->ulid}/tags", ['tag' => $tag->id])->assertForbidden();
        $this->actingAs($this->director)->get('/admin/tags')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Registry/Admin/Tags')->where('tags', [['id' => $tag->id, 'taxonomy' => 'segment', 'name' => 'Premium']]));

        $this->actingAs($this->analyst)->post("/competitors/{$competitor->ulid}/tags", ['tag' => $tag->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($reader)->delete("/competitors/{$competitor->ulid}/tags/{$tag->id}")->assertForbidden();
        $this->assertSame(1, DB::table('taggables')->count());
    }

    public function test_tags_are_added_edited_and_removed_with_an_audit(): void
    {
        $this->actingAs($this->director)->post('/admin/tags', ['taxonomy' => ' Segment ', 'name' => ' Premium  Brands '])->assertSessionHasNoErrors()->assertRedirect();
        $tag = Tag::query()->sole();
        $this->assertSame(['segment', 'Premium Brands'], [$tag->taxonomy, $tag->name]);
        $this->actingAs($this->director)->post('/admin/tags', ['taxonomy' => 'segment', 'name' => 'PREMIUM BRANDS'])
            ->assertSessionHasErrors(['name' => 'This taxonomy already has a tag with this name.']);
        $this->actingAs($this->director)->post('/admin/tags', ['taxonomy' => 'strategy', 'name' => 'Premium Brands'])->assertSessionHasNoErrors(); // another taxonomy
        $this->actingAs($this->director)->post('/admin/tags', ['taxonomy' => '1st', 'name' => 'Value'])->assertSessionHasErrors('taxonomy');
        $this->actingAs($this->director)->post('/admin/tags', ['taxonomy' => 'segment', 'name' => ' '])->assertSessionHasErrors('name');

        $this->actingAs($this->director)->put("/admin/tags/{$tag->id}", ['taxonomy' => 'tier', 'name' => 'Premium'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['tier', 'Premium'], [$tag->refresh()->taxonomy, $tag->name]);
        $this->actingAs($this->director)->delete("/admin/tags/{$tag->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['strategy'], Tag::query()->pluck('taxonomy')->all());

        $this->assertEquals([
            ['tag.created', ['taxonomy' => 'segment']],
            ['tag.created', ['taxonomy' => 'strategy']],
            ['tag.updated', ['fields' => ['taxonomy', 'name']]],
            ['tag.removed', ['taxonomy' => 'tier']],
        ], DB::table('audit_events')->where('action', 'like', 'tag.%')->orderBy('id')->get()->map(fn ($e) => [$e->action, json_decode((string) $e->context, true)])->all());
    }

    public function test_tagging_is_audited_on_the_competitor_and_repeats_change_nothing(): void
    {
        $tag = $this->tag('segment', 'Premium');
        $competitor = $this->competitor('Acme Foods');

        $this->actingAs($this->analyst)->post("/competitors/{$competitor->ulid}/tags", ['tag' => $tag->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->analyst)->post("/competitors/{$competitor->ulid}/tags", ['tag' => $tag->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, DB::table('taggables')->where('competitor_id', $competitor->id)->count());
        $this->actingAs($this->analyst)->post("/competitors/{$competitor->ulid}/tags", ['tag' => 999999])->assertSessionHasErrors(['tag' => 'Choose a listed tag.']);

        $this->actingAs($this->analyst)->get("/competitors/{$competitor->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags', [['id' => $tag->id, 'taxonomy' => 'segment', 'name' => 'Premium']])->has('tagOptions', 1));

        $this->actingAs($this->analyst)->delete("/competitors/{$competitor->ulid}/tags/{$tag->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->analyst)->delete("/competitors/{$competitor->ulid}/tags/{$tag->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, DB::table('taggables')->count());

        $events = DB::table('audit_events')->where('action', 'like', 'competitor.%tagged')->orderBy('id')->get();
        $this->assertEquals([
            ['competitor.tagged', $competitor->ulid, 'internal', ['tag_id' => $tag->id, 'taxonomy' => 'segment']],
            ['competitor.untagged', $competitor->ulid, 'internal', ['tag_id' => $tag->id, 'taxonomy' => 'segment']],
        ], $events->map(fn ($e) => [$e->action, $e->subject_ulid, $e->classification, json_decode((string) $e->context, true)])->all());
    }

    public function test_hidden_competitors_are_neither_tagged_nor_listed(): void
    {
        $tag = $this->tag('segment', 'Premium');
        $hidden = $this->competitor('Secret Snacks', Classification::Confidential);
        $visible = $this->competitor('Acme Foods');
        $this->link($tag, $hidden);
        $this->link($tag, $visible);

        $this->actingAs($this->analyst)->post("/competitors/{$hidden->ulid}/tags", ['tag' => $tag->id])->assertNotFound();
        $this->actingAs($this->analyst)->delete("/competitors/{$hidden->ulid}/tags/{$tag->id}")->assertNotFound();
        $this->assertSame(2, DB::table('taggables')->count());

        $this->actingAs($this->analyst)->get("/competitors?tag={$tag->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('competitors', 1)->where('competitors.0.ulid', $visible->ulid)->where('competitors.0.tags', [['id' => $tag->id, 'taxonomy' => 'segment', 'name' => 'Premium']]));
        $this->actingAs($this->director)->get("/competitors?tag={$tag->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('competitors', 2));
    }

    public function test_the_competitor_list_filters_by_tag(): void
    {
        $premium = $this->tag('segment', 'Premium');
        $value = $this->tag('segment', 'Value');
        $acme = $this->competitor('Acme Foods');
        $beta = $this->competitor('Beta Snacks');
        $this->link($premium, $acme);
        $this->link($value, $beta);

        $list = fn (string $query): array => array_column($this->actingAs($this->analyst)->get("/competitors?$query")->assertOk()->inertiaProps('competitors'), 'ulid');
        $this->assertSame([$acme->ulid, $beta->ulid], $list(''));
        $this->assertSame([$acme->ulid], $list("tag={$premium->id}"));
        $this->assertSame([$beta->ulid], $list("tag={$value->id}"));
        $this->assertSame([], $list('tag=999999'));
        $this->actingAs($this->analyst)->get('/competitors?tag=abc')->assertSessionHasErrors('tag');
    }

    public function test_a_tag_in_use_is_kept_by_the_service_and_the_database(): void
    {
        $tag = $this->tag('segment', 'Premium');
        $hidden = $this->competitor('Secret Snacks', Classification::Restricted);
        $this->link($tag, $hidden);

        $this->actingAs($this->director)->delete("/admin/tags/{$tag->id}")
            ->assertSessionHasErrors(['tag' => 'This tag is in use; it can be removed once nothing carries it.']);
        $this->assertSame(1, Tag::query()->count());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('taggables_tag_id_foreign');
        DB::table('tags')->where('id', $tag->id)->delete();
    }

    public function test_the_database_refuses_invalid_tags_and_links(): void
    {
        $tag = $this->tag('segment', 'Premium');
        foreach (['taxonomy' => ['taxonomy' => 'Segment'], 'name' => ['name' => 'Premium ']] as $check => $overrides) {
            try {
                DB::table('tags')->insert($overrides + ['taxonomy' => 'segment', 'name' => 'Value', 'created_at' => now(), 'updated_at' => now()]);
                $this->fail("The database accepted a tag breaking tags_{$check}_check.");
            } catch (QueryException $e) {
                $this->assertStringContainsString("tags_{$check}_check", $e->getMessage());
            }
        }
        foreach (['taggables_type_check' => ['taggable_type' => 'product', 'taggable_id' => 1], 'taggables_competitor_id_foreign' => ['taggable_id' => 999999]] as $constraint => $overrides) {
            try {
                DB::table('taggables')->insert($overrides + ['tag_id' => $tag->id, 'taggable_type' => 'competitor', 'created_at' => now()]);
                $this->fail("The database accepted a link breaking $constraint.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }
    }

    private function tag(string $taxonomy, string $name): Tag
    {
        $tag = new Tag;
        $tag->forceFill(['taxonomy' => $taxonomy, 'name' => $name])->save();

        return $tag;
    }

    private function link(Tag $tag, Competitor $competitor): void
    {
        DB::table('taggables')->insert(['tag_id' => $tag->id, 'taggable_type' => Tag::COMPETITOR, 'taggable_id' => $competitor->id, 'created_at' => now()]);
    }

    private function competitor(string $name, Classification $classification = Classification::Internal): Competitor
    {
        $competitor = new Competitor;
        $competitor->forceFill([
            'ulid' => (string) Str::ulid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'lifecycle' => 'active',
            'classification' => $classification, 'created_by' => $this->analyst->id,
        ])->save();

        return $competitor;
    }
}
