<?php

namespace Modules\Registry;

use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;
use Modules\Registry\Contracts\CompetitorLookup;
use Modules\Registry\Lookup\EloquentCompetitorLookup;

/**
 * Registry (M02–M04, docs/03): competitors (P1-20), aliases and relationships (P1-21); products, markets and taxonomies
 * follow in Sprint 1.2. Competitor entries are classified records without competitor/market scopes (PO decision 2026-11-01):
 * classification and AccessScope decide who sees them.
 */
class RegistryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(CompetitorLookup::class, EloquentCompetitorLookup::class);
    }

    public function boot(): void
    {
        parent::boot();

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
        // Domain events (docs/03: CompetitorCreated, CompetitorUpdated, CompetitorArchived) are defined with their first
        // consumer, the Phase 2 search indexer; the outbox refuses events that no handler subscribes to.
    }
}
