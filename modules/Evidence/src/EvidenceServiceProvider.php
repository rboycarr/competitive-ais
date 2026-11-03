<?php

namespace Modules\Evidence;

use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Evidence (M09, docs/03): uploads, quarantine, scanning, content-addressed storage, custody. P1-32a stores uploads
 * in quarantine with their custody trail; the scan and promotion follow in P1-32b, downloads in P1-34, legal hold in
 * P1-35, scopes in P1-36, the source policy at capture in P1-37. Uploads are untrusted (docs/07 T7).
 */
class EvidenceServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Codes only: never the title, file name, URL or description (audit context holds no user input).
        $actions = $this->app->make(AuditActions::class);
        $actions->define('evidence.uploaded', ['classification' => 'code']);
        $actions->define('evidence.scanned', ['result' => 'code', 'sha256' => 'sha256']);
        $actions->define('evidence.rejected', ['reason' => 'code']);
    }
}
