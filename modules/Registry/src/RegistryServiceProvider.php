<?php

namespace Modules\Registry;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;
use Modules\Registry\Console\VerifyCategoryTree;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Contracts\MarketLookup;
use Modules\Registry\Contracts\ProductLookup;
use Modules\Registry\Lookup\EloquentCompetitorLookup;
use Modules\Registry\Lookup\EloquentMarketLookup;
use Modules\Registry\Lookup\EloquentProductLookup;

/**
 * Registry (M02–M04, docs/03): competitors (P1-20), aliases and relationships (P1-21), brands and the category tree
 * (P1-22a), products and variants (P1-22b), currencies, markets and regions (P1-23a), channels, retailers and tags
 * (P1-23b). Competitor entries are classified records without
 * competitor/market scopes (PO decision 2026-11-01): classification and AccessScope decide who sees them.
 */
class RegistryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(CompetitorLookup::class, EloquentCompetitorLookup::class);
        $this->app->bind(MarketLookup::class, EloquentMarketLookup::class);
        $this->app->bind(ProductLookup::class, EloquentProductLookup::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyCategoryTree::class]);
        }
        // Daily, like the audit chain check: rows written around CategoryService show up here (TASK-0036 #1).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('registry:verify-categories')->daily();
        });

        $audit = $this->app->make(AuditActions::class);
        $audit->define('competitor.created', ['lifecycle' => 'code', 'classification' => 'code']);
        $audit->define('competitor.updated', ['fields' => 'code[]']);
        $audit->define('competitor.lifecycle_changed', ['from' => 'code', 'to' => 'code']);
        // P1-21: IDs and codes only, never the alias text or notes (audit context holds no user input).
        $audit->define('competitor.alias_added', ['alias_id' => 'id', 'alias_type' => 'code']);
        $audit->define('competitor.alias_removed', ['alias_id' => 'id', 'alias_type' => 'code']);
        $audit->define('competitor.relationship_added', ['parent' => 'ulid', 'child' => 'ulid', 'relation_type' => 'code']);
        $audit->define('competitor.relationship_updated', ['fields' => 'code[]']);
        $audit->define('competitor.relationship_removed', ['parent' => 'ulid', 'child' => 'ulid', 'relation_type' => 'code']);
        // P1-22a: owner "own" or "competitor" (with its ULID); category parents by ID, absent for the top level.
        foreach (['brand.created', 'brand.renamed', 'brand.removed'] as $action) {
            $audit->define($action, ['owner' => 'code', 'competitor' => 'ulid']);
        }
        $audit->define('category.created', ['parent_id' => 'id']);
        $audit->define('category.renamed');
        $audit->define('category.moved', ['from_parent' => 'id', 'to_parent' => 'id', 'subcategories' => 'int']);
        $audit->define('category.removed', ['parent_id' => 'id']);
        // P1-22b: classified with the higher of the product's and its competitor's classification.
        $audit->define('product.created', ['owner' => 'code', 'competitor' => 'ulid', 'status' => 'code', 'classification' => 'code']);
        $audit->define('product.updated', ['fields' => 'code[]']);
        $audit->define('variant.created', ['product' => 'ulid']);
        $audit->define('variant.updated', ['fields' => 'code[]']);
        // P1-23a: unclassified reference data (internal).
        $audit->define('market.created');
        $audit->define('market.updated', ['fields' => 'code[]']);
        $audit->define('region.created', ['market' => 'ulid', 'level' => 'code', 'parent_id' => 'id']);
        $audit->define('region.renamed', ['market' => 'ulid']);
        $audit->define('region.removed', ['market' => 'ulid', 'level' => 'code', 'parent_id' => 'id']);
        // P1-23b: unclassified reference data (internal); a currency has no ID, so its lower-cased code is the context.
        $audit->define('currency.created', ['currency' => 'code', 'minor_units' => 'int']);
        $audit->define('currency.updated', ['currency' => 'code', 'fields' => 'code[]']);
        $audit->define('channel.created', ['channel_type' => 'code']);
        $audit->define('channel.updated', ['fields' => 'code[]']);
        $audit->define('channel.removed', ['channel_type' => 'code']);
        $audit->define('retailer.created', ['market' => 'ulid', 'channel_id' => 'id']);
        $audit->define('retailer.updated', ['fields' => 'code[]']);
        $audit->define('retailer.removed', ['market' => 'ulid', 'channel_id' => 'id']);
        $audit->define('tag.created', ['taxonomy' => 'code']);
        $audit->define('tag.updated', ['fields' => 'code[]']);
        $audit->define('tag.removed', ['taxonomy' => 'code']);
        // Tagging is recorded on the competitor, with its classification.
        $audit->define('competitor.tagged', ['tag_id' => 'id', 'taxonomy' => 'code']);
        $audit->define('competitor.untagged', ['tag_id' => 'id', 'taxonomy' => 'code']);
        // Domain events (docs/03: CompetitorCreated, CompetitorUpdated, CompetitorArchived) are defined with their first
        // consumer, the Phase 2 search indexer; the outbox refuses events that no handler subscribes to.
    }
}
