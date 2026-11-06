<?php

namespace Modules\Evidence;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Evidence\Console\DispatchPendingScans;
use Modules\Evidence\Console\ReconcileBlobs;
use Modules\Evidence\Console\SweepQuarantine;
use Modules\Evidence\Contracts\EvidenceLinking;
use Modules\Evidence\Contracts\EvidenceProvenance;
use Modules\Evidence\Lookup\EloquentEvidenceLinking;
use Modules\Evidence\Lookup\EloquentEvidenceProvenance;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Evidence (M09, docs/03): uploads, quarantine, scanning, content-addressed storage, custody. P1-32a stores uploads
 * in quarantine with their custody trail; P1-32b scans them and promotes clean bytes to content-addressed storage, or
 * rejects them. P1-34 serves downloads through short-lived links, P1-35 adds legal hold; scopes in P1-36, the source policy at capture in P1-37.
 * Uploads are untrusted (docs/07 T7).
 */
class EvidenceServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Intelligence cites evidence through this contract (P1-41).
        $this->app->bind(EvidenceLinking::class, EloquentEvidenceLinking::class);
        // P1-45: the provenance view.
        $this->app->bind(EvidenceProvenance::class, EloquentEvidenceProvenance::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchPendingScans::class, SweepQuarantine::class, ReconcileBlobs::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A lost dispatch or a dead worker must not leave an upload in quarantine for good (TASK-0070 #1).
            $schedule->command('evidence:dispatch-scans')->everyFiveMinutes();
            // P1-38: quarantine objects no asset waits for (TASK-0064 #2); blob holds and retention against the store (TASK-0074).
            $schedule->command('evidence:sweep-quarantine')->hourly();
            $schedule->command('evidence:reconcile-blobs')->dailyAt('02:30');
        });

        // Codes only: never the title, file name, URL or description (audit context holds no user input).
        $actions = $this->app->make(AuditActions::class);
        $actions->define('evidence.uploaded', ['classification' => 'code', 'source_policy' => 'id']); // source_policy: the policy in force at capture (P1-37)
        $actions->define('evidence.scanned', ['result' => 'code', 'sha256' => 'sha256']);
        $actions->define('evidence.rejected', ['reason' => 'code']);
        $actions->define('evidence.downloaded', ['sha256' => 'sha256']);
        $actions->define('evidence.hold_set', ['blob_hold' => 'code']);
        $actions->define('evidence.hold_released', ['blob_hold' => 'code']);
        // P1-36b: scopes taken from the item a claim cites the evidence in (counts; the IDs are in the custody trail).
        $actions->define('evidence.scope_inherited', ['item_ulid' => 'ulid', 'competitors' => 'int', 'markets' => 'int']);
        // P1-38: what reconciliation changed or found on a blob (each key optional; EvidenceBlobReconciler).
        $actions->define('evidence.blob_reconciled', ['record_hold' => 'code', 'retention' => 'code', 'object_hold' => 'code', 'object_retention' => 'code']);

        // P1-34: downloads per user, so a stolen session cannot pull the store in bulk unnoticed.
        RateLimiter::for('evidence-downloads', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
