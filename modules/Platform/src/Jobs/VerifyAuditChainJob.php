<?php

namespace Modules\Platform\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Platform\Audit\AuditVerifier;
use Modules\Platform\Events\AuditVerificationFailed;

/** Scheduled hourly (PlatformServiceProvider). Logs critically and raises an event on any mismatch. */
class VerifyAuditChainJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(AuditVerifier $verifier): void
    {
        $issues = $verifier->verify();
        if ($issues !== []) {
            Log::critical('Audit trail verification failed', ['issues' => $issues]);
            AuditVerificationFailed::dispatch($issues);
        }
    }
}
