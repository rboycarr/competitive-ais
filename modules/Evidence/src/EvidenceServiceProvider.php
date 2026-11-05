<?php

namespace Modules\Evidence;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Evidence\Console\DispatchPendingScans;
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
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchPendingScans::class]);
        }
        // A lost dispatch or a dead worker must not leave an upload in quarantine for good (TASK-0070 #1).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('evidence:dispatch-scans')->everyFiveMinutes();
        });

        // Codes only: never the title, file name, URL or description (audit context holds no user input).
        $actions = $this->app->make(AuditActions::class);
        $actions->define('evidence.uploaded', ['classification' => 'code', 'source_policy' => 'id']); // source_policy: the policy in force at capture (P1-37)
        $actions->define('evidence.scanned', ['result' => 'code', 'sha256' => 'sha256']);
        $actions->define('evidence.rejected', ['reason' => 'code']);
        $actions->define('evidence.downloaded', ['sha256' => 'sha256']);
        $actions->define('evidence.hold_set', ['blob_hold' => 'code']);
        $actions->define('evidence.hold_released', ['blob_hold' => 'code']);

        // P1-34: downloads per user, so a stolen session cannot pull the store in bulk unnoticed.
        RateLimiter::for('evidence-downloads', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
