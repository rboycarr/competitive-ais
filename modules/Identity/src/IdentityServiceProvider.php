<?php

namespace Modules\Identity;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Identity\Console\InviteUser;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Support\ModuleServiceProvider;

/**
 * Identity (M01, docs/03): users, invitations, roles and permissions; login, MFA, teams and access grants follow in
 * Sprint 1.1.
 */
class IdentityServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom($this->modulePath('resources/views'), 'identity');

        $actions = $this->app->make(AuditActions::class);
        $actions->define('user.invited', ['invitation_ulid' => 'ulid', 'role' => 'code', 'clearance' => 'code', 'replaced' => 'int']);
        $actions->define('user.activated', ['invitation_ulid' => 'ulid', 'role' => 'code', 'clearance' => 'code']);

        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([InviteUser::class]);
        }
    }
}
