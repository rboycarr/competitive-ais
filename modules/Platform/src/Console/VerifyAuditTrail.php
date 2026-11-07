<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Audit\AuditVerifier;

/**
 * Checks the audit trail against its signed manifests; exits 1 on any issue, which is also logged critically
 * (P1-05, P1-50, docs/10 §5). Runs in the auditor service, which holds the keys (audit:work runs it hourly).
 */
class VerifyAuditTrail extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit trail against its signed manifests';

    public function handle(AuditVerifier $verifier): int
    {
        $issues = $verifier->verifyAndReport();
        if ($issues === []) {
            $this->components->info('Audit trail matches its signed manifests.');

            return self::SUCCESS;
        }

        $this->components->error(count($issues).' audit trail issue(s):');
        $this->table(['type', 'severity', 'detail'], $issues);

        return self::FAILURE;
    }
}
