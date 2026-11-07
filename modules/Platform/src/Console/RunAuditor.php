<?php

namespace Modules\Platform\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Platform\Audit\AuditAnchor;
use Modules\Platform\Audit\AuditVerifier;
use Throwable;

/**
 * The auditor service's process (P1-50, PO decision O43): closes, signs and exports a checkpoint every five minutes
 * and verifies the trail against the signed manifests every hour. A loop of its own rather than schedule:work, because
 * this service must run only the audit work, never the other modules' scheduled jobs. A failed run is logged
 * critically and retried at the next turn; SIGTERM ends the loop between runs.
 */
class RunAuditor extends Command
{
    protected $signature = 'audit:work {--once : Run one checkpoint and one verification, then exit}';

    protected $description = 'Run the auditor: signed checkpoints every five minutes, verification every hour';

    private bool $stopping = false;

    public function handle(AuditAnchor $anchor, AuditVerifier $verifier): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $verifyAt = CarbonImmutable::now();
        do {
            $ok = $this->attempt('checkpoint', fn () => $anchor->checkpoint());
            if (CarbonImmutable::now()->gte($verifyAt)) {
                $ok = $this->attempt('verification', fn () => $verifier->verifyAndReport() === []) && $ok;
                $verifyAt = CarbonImmutable::now()->addHour();
            }
            if ($this->option('once')) {
                break;
            }
            for ($second = 0; $second < 300 && ! $this->stopping; $second++) {
                sleep(1);
            }
        } while (! $this->stopping);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Runs one piece of audit work; false when it failed or found issues (already logged critically). */
    private function attempt(string $work, callable $run): bool
    {
        try {
            return $run() !== false;
        } catch (Throwable $e) {
            Log::critical("Audit $work failed", ['exception' => $e::class, 'message' => $e->getMessage()]);
            report($e);
            $this->components->error("Audit $work failed: {$e->getMessage()}");

            return false;
        }
    }
}
