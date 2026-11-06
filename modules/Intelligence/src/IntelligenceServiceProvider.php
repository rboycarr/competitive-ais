<?php

namespace Modules\Intelligence;

use Modules\Intelligence\Contracts\IntelligenceReferences;
use Modules\Intelligence\Lookup\EloquentIntelligenceReferences;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Intelligence (M10, docs/03): items, their subjects and their typed, rated claims (P1-40), and the evidence the claims
 * cite (P1-41), submission (P1-42) and the verification queue (P1-43). Publication (P1-44) and the provenance view
 * (P1-45) follow.
 * Items are classified records scoped by their competitor and market subjects (all-of); unreleased items are seen only
 * per statusVisible (docs/07 §3).
 */
class IntelligenceServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // The Registry's archive guard asks through this contract (P1-40).
        $this->app->bind(IntelligenceReferences::class, EloquentIntelligenceReferences::class);
    }

    public function boot(): void
    {
        parent::boot();

        $audit = $this->app->make(AuditActions::class);
        // Codes, counts and IDs only: never a title, summary or statement (audit context holds no user input).
        $audit->define('intelligence.created', ['item_type' => 'code', 'high_impact' => 'bool', 'competitors' => 'int', 'products' => 'int', 'markets' => 'int']);
        $audit->define('intelligence.updated', ['fields' => 'code[]', 'version' => 'int']);
        $claim = ['claim_ulid' => 'ulid', 'claim_type' => 'code', 'rating' => 'code', 'version' => 'int'];
        $audit->define('intelligence.claim_added', $claim);
        $audit->define('intelligence.claim_updated', $claim + ['fields' => 'code[]']);
        $audit->define('intelligence.claim_withdrawn', $claim);
        $audit->define('intelligence.claim_moved', $claim + ['direction' => 'code']);
        // P1-41: never the locator or excerpt.
        $link = ['claim_ulid' => 'ulid', 'evidence_ulid' => 'ulid', 'relation' => 'code', 'version' => 'int'];
        $audit->define('intelligence.evidence_linked', $link);
        $audit->define('intelligence.evidence_unlinked', $link);
        // P1-42: the workflow.
        $audit->define('intelligence.submitted', ['version' => 'int', 'claims' => 'int']);
        // P1-43: never the rationale (it is on the review row).
        $audit->define('intelligence.reviewer_assigned', ['decision' => 'code', 'reviewer_ulid' => 'ulid', 'review_ulid' => 'ulid', 'version' => 'int']);
        $audit->define('intelligence.review_started', ['version' => 'int']);
        $audit->define('intelligence.reviewed', ['decision' => 'code', 'review_ulid' => 'ulid', 'version' => 'int']);
        // P1-44: never the retraction reason (it is on the retraction row).
        $audit->define('intelligence.published', ['approval_ulid' => 'ulid', 'review_ulid' => 'ulid', 'version' => 'int']);
        $audit->define('intelligence.superseded', ['replacement_ulid' => 'ulid', 'version' => 'int']);
        $audit->define('intelligence.retracted', ['replacement' => 'bool', 'version' => 'int']);
        $audit->define('intelligence.replacement_drafted', ['replaces_ulid' => 'ulid']);
        // Domain events (docs/03: IntelligenceSubmitted, …) are defined with their first consumer.
    }
}
