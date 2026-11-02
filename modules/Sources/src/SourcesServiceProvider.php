<?php

namespace Modules\Sources;

use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Sources (M06, M07, docs/03): the source registry. P1-30 lets analysts propose a source; policy decisions and the
 * lifecycle follow in P1-31. Sources are classified records without competitor/market scopes: classification and
 * AccessScope decide who sees them. Collection (Phase 3) stays off behind the `collection` feature flag.
 */
class SourcesServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $audit = $this->app->make(AuditActions::class);
        // Codes only: never the name, URL or description (audit context holds no user input).
        $audit->define('source.proposed', ['source_type' => 'code', 'method' => 'code', 'classification' => 'code']);
        // Domain events (docs/03: SourceProposed, …) are defined with their first consumer; the outbox refuses events
        // that no handler subscribes to.
    }
}
