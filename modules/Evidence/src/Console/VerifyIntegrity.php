<?php

namespace Modules\Evidence\Console;

use Illuminate\Console\Command;
use Modules\Evidence\Actions\EvidenceIntegrityService;
use Modules\Evidence\Models\EvidenceBlob;
use Throwable;

/**
 * Re-hashes a sample of stored evidence (P1-48, EvidenceIntegrityService): the blobs not yet marked, least recently
 * verified first (never verified first), at most --limit per run, so every blob comes round in turn. One blob that
 * fails (e.g. the store cannot be reached) does not stop the rest. Exits 1 when any blob failed its check or could not
 * be checked, for the operator. Scheduled daily.
 */
class VerifyIntegrity extends Command
{
    protected $signature = 'evidence:verify-integrity {--limit=50 : How many blobs to re-hash in this run}';

    protected $description = 'Re-hash stored evidence and flag what no longer matches';

    public function handle(EvidenceIntegrityService $integrity): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->components->error('The limit must be a whole number from 1 to 10000.');

            return self::INVALID;
        }

        $counts = ['ok' => 0, 'mismatch' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0];
        $ids = EvidenceBlob::query()->where('integrity_status', 'ok')->orderBy('last_verified_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $counts[$integrity->verify((int) $id)]++;
            } catch (Throwable $e) {
                $counts['failed']++;
                report($e);
            }
        }

        $summary = "Blobs verified: {$counts['ok']}; mismatched: {$counts['mismatch']}; missing: {$counts['missing']}; already marked: {$counts['skipped']}; failed: {$counts['failed']}.";
        if ($counts['mismatch'] + $counts['missing'] + $counts['failed'] > 0) {
            $this->components->error($summary);

            return self::FAILURE;
        }
        $this->components->info($summary);

        return self::SUCCESS;
    }
}
