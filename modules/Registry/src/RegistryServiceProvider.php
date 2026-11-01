<?php

namespace Modules\Registry;

use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Registry (M02–M04, docs/03): competitors (P1-20); aliases, relationships, products, markets and taxonomies follow in
 * Sprint 1.2. Competitor entries are classified records without competitor/market scopes (PO decision 2026-11-01):
 * classification and AccessScope decide who sees them.
 */
class RegistryServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $audit = $this->app->make(AuditActions::class);
        $audit->define('competitor.created', ['lifecycle' => 'code', 'classification' => 'code']);
        $audit->define('competitor.updated', ['fields' => 'code[]']);
        $audit->define('competitor.lifecycle_changed', ['from' => 'code', 'to' => 'code']);
        // Domain events (docs/03: CompetitorCreated, CompetitorUpdated, CompetitorArchived) are defined with their first
        // consumer, the Phase 2 search indexer; the outbox refuses events that no handler subscribes to.
    }
}
