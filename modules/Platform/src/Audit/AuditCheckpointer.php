<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Closes the next range of audit rows into a checkpoint with a Merkle root (ADR-0006).
 *
 * Only rows older than the lag window are closed, so transactions that are still open (and may commit an earlier
 * ID later) are not cut off. A row that commits inside a closed range anyway is reported by AuditVerifier as
 * late_commit. Idempotent: with nothing new to close it does nothing, and a concurrent run that closes the same
 * range first wins through the unique keys.
 *
 * Signed manifests exported to the audit-anchor bucket follow in P1-50.
 */
class AuditCheckpointer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditHasher $hasher,
        private readonly Config $config,
    ) {}

    /**
     * @return array{from: int, to: int, count: int, root: string}|null the new checkpoint, or null if none was due
     *
     * @phpstan-impure
     */
    public function close(): ?array
    {
        $db = $this->db->connection();
        $generation = (int) $this->config->get('audit.generation');
        $now = CarbonImmutable::now('UTC');
        $watermark = $now->subMinutes((int) $this->config->get('audit.lag_minutes'))->format('Y-m-d H:i:s.u');

        $last = (int) $db->table('audit_checkpoints')->where('generation', $generation)->max('to_audit_id');
        $to = $db->table('audit_events')->where('id', '>', $last)->where('occurred_at', '<', $watermark)->max('id');
        if ($to === null) {
            return null;
        }

        $rows = [];
        foreach ($db->table('audit_events')->select(['id', 'row_hash'])->where('id', '>', $last)->where('id', '<=', $to)->orderBy('id')->cursor() as $row) {
            $rows[] = ['id' => (int) $row->id, 'row_hash' => (string) $row->row_hash];
        }
        $checkpoint = ['from' => $last + 1, 'to' => (int) $to, 'count' => count($rows), 'root' => $this->hasher->merkleRoot($rows)];

        try {
            $db->table('audit_checkpoints')->insert([
                'generation' => $generation,
                'from_audit_id' => $checkpoint['from'],
                'to_audit_id' => $checkpoint['to'],
                'event_count' => $checkpoint['count'],
                'merkle_root' => $checkpoint['root'],
                'watermark_at' => $watermark,
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // a concurrent run closed this range first
        }

        return $checkpoint;
    }
}
