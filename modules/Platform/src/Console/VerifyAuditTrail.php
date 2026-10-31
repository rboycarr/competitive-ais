<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Modules\Platform\Audit\AuditVerifier;

/** Checks the audit trail against its checkpoints; exits 1 on any issue (P1-05, docs/10 §5). */
class VerifyAuditTrail extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the audit trail against its checkpoints';

    public function handle(AuditVerifier $verifier): int
    {
        $issues = $verifier->verify();
        if ($issues === []) {
            $this->components->info('Audit trail matches its checkpoints.');

            return self::SUCCESS;
        }

        $this->components->error(count($issues).' audit trail issue(s):');
        $this->table(['type', 'severity', 'detail'], $issues);

        return self::FAILURE;
    }
}
