<?php

namespace Modules\Platform\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watches the auditor from outside (P1-50, TASK-0096 #5): run by the ordinary scheduler, which holds no key, so an
 * auditor that stopped is still noticed. Reads the database only: audit rows older than the lag window plus the stale
 * window that are past the last exported checkpoint are logged critically (exit 1). It proves that anchoring runs, not
 * that the trail is intact; audit:verify in the auditor does that.
 */
class CheckAuditFreshness extends Command
{
    protected $signature = 'audit:freshness';

    protected $description = 'Alert when audit rows wait too long for a signed checkpoint (the auditor may be down)';

    public function handle(): int
    {
        $signedTo = (int) DB::table('audit_checkpoints as c')->join('audit_anchors as a', 'a.checkpoint_id', '=', 'c.id')
            ->where('c.generation', (int) config('audit.generation'))->max('c.to_audit_id');
        $dueBefore = CarbonImmutable::now('UTC')
            ->subMinutes((int) config('audit.lag_minutes') + (int) config('audit.anchor.stale_minutes'))
            ->format('Y-m-d H:i:s.u');
        $overdue = DB::table('audit_events')->where('id', '>', $signedTo)->where('occurred_at', '<', $dueBefore)->count();

        if ($overdue === 0) {
            $this->components->info('Audit anchoring is up to date.');

            return self::SUCCESS;
        }

        Log::critical('Audit rows are not being anchored; check the auditor service', ['overdue' => $overdue, 'signed_to' => $signedTo]);
        $this->components->error("$overdue audit rows from before $dueBefore UTC are past the last exported checkpoint (ID $signedTo). Check the auditor service.");

        return self::FAILURE;
    }
}
