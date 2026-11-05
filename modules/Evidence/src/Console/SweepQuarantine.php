<?php

namespace Modules\Evidence\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use League\Flysystem\FileAttributes;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Evidence\Models\EvidenceAsset;
use RuntimeException;
use Throwable;

/**
 * Removes quarantine objects that no asset waits for (P1-38, TASK-0064 #2): an upload whose records failed and whose
 * cleanup failed too, or a scanned upload whose quarantine object could not be deleted (both logged as
 * `evidence.quarantine_orphan`). An object is an orphan when its key is `quarantine/<ULID>`, it is older than
 * `evidence.quarantine_orphan_minutes`, and no asset holds that key (an asset holds it exactly while quarantined).
 * The age keeps an upload's object, written just before its asset is committed, from being taken for an orphan.
 * Keys of any other form are counted and left for the operator. Each removal is logged by its generated key only
 * (`evidence.quarantine_orphan_removed`); one failed removal does not stop the rest, and the command then exits 1.
 * Scheduled hourly.
 */
class SweepQuarantine extends Command
{
    private const KEY = '/^quarantine\/[0-9A-HJKMNP-TV-Z]{26}$/';

    protected $signature = 'evidence:sweep-quarantine';

    protected $description = 'Remove quarantine objects that no evidence waits for';

    public function handle(Filesystems $storage): int
    {
        $disk = $storage->disk(EvidenceUploadService::QUARANTINE_DISK);
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('The quarantine disk cannot be listed.');
        }
        $before = CarbonImmutable::now('UTC')->subMinutes((int) config('evidence.quarantine_orphan_minutes'))->getTimestamp();
        $removed = $failed = $unexpected = 0;
        $batch = [];
        $sweep = function (array $keys) use ($disk, &$removed, &$failed): void {
            $waiting = EvidenceAsset::query()->whereIn('quarantine_key', $keys)->pluck('quarantine_key')->all();
            foreach (array_diff($keys, $waiting) as $key) {
                try {
                    if ($disk->delete($key) === false) {
                        throw new RuntimeException('The quarantine object was not deleted.');
                    }
                    $removed++;
                    Log::info('evidence.quarantine_orphan_removed', ['quarantine_key' => $key]);
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('evidence.quarantine_orphan', ['quarantine_key' => $key, 'cleanup_error' => $e::class]);
                }
            }
        };

        foreach ($disk->getDriver()->listContents('quarantine', true) as $item) {
            if (! $item instanceof FileAttributes) {
                continue;
            }
            $key = $item->path();
            if (preg_match(self::KEY, $key) !== 1) {
                $unexpected++;

                continue;
            }
            $modified = $item->lastModified() ?? $disk->lastModified($key);
            if ($modified >= $before) {
                continue; // too recent: its upload may still be committing
            }
            $batch[] = $key;
            if (count($batch) === 500) {
                $sweep($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $sweep($batch);
        }

        $summary = "Orphaned quarantine objects removed: $removed; failed: $failed; unexpected keys left: $unexpected.";
        if ($failed > 0 || $unexpected > 0) {
            $this->components->error($summary);

            return self::FAILURE;
        }
        $this->components->info($summary);

        return self::SUCCESS;
    }
}
