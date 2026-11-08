<?php

namespace Modules\Sources;

use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Dashboard\DashboardTiles;
use Modules\Platform\Support\ModuleServiceProvider;
use Modules\Sources\Contracts\SourceLookup;
use Modules\Sources\Dashboard\SourcesDashboardTiles;
use Modules\Sources\Lookup\EloquentSourceLookup;

/**
 * Sources (M06, M07, docs/03): the source registry. P1-30 lets analysts propose a source; P1-31a adds the policy review
 * (submit, director decision, policy versions); P1-31b suspending, reinstating and retiring. Sources are classified
 * records without competitor/market scopes: classification and AccessScope decide who sees them. Collection (Phase 3) stays off behind the `collection` feature flag.
 */
class SourcesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // P1-37: evidence capture records the policy in force through this contract.
        $this->app->bind(SourceLookup::class, EloquentSourceLookup::class);
        // P1-46: the dashboard.
        $this->app->tag([SourcesDashboardTiles::class], DashboardTiles::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $audit = $this->app->make(AuditActions::class);
        // Codes only: never the name, URL or description (audit context holds no user input).
        $audit->define('source.proposed', ['source_type' => 'code', 'method' => 'code', 'classification' => 'code']);
        $audit->define('source.submitted');
        $audit->define('source.policy_decided', ['decision' => 'code', 'version' => 'int', 'robots_decision' => 'code', 'contains_personal_data' => 'bool']);
        foreach (['source.suspended', 'source.reinstated', 'source.retired'] as $action) {
            $audit->define($action, ['from_status' => 'code', 'reason' => 'code']);
        }
        // Domain events (docs/03: SourceProposed, …) are defined with their first consumer; the outbox refuses events
        // that no handler subscribes to.
    }
}
