<?php

namespace Modules\Platform\Audit;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;

/**
 * Checks the audit trail against its checkpoints (ADR-0006, docs/09 #18). Reports, never repairs:
 *
 * - row_tampered (critical): a row's stored hash does not match its columns
 * - rows_missing (critical): a closed range holds fewer rows than its checkpoint counted
 * - late_commit (high): a closed range holds more rows than counted; a transaction committed after the range
 *   closed (investigate per runbook; re-anchoring follows in P1-50)
 * - root_mismatch (critical): same count, different Merkle root (rows replaced or IDs changed)
 * - chain_gap (critical): checkpoints of a generation do not cover IDs contiguously from 1
 *
 * Detects changes made without also rewriting the checkpoints. An attacker who can rewrite both is caught by the
 * signed manifests in the audit-anchor bucket (P1-50).
 */
class AuditVerifier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditHasher $hasher,
        private readonly Config $config,
    ) {}

    /**
     * @return list<array{type: string, severity: string, detail: string}>
     */
    public function verify(): array
    {
        $db = $this->db->connection();
        $issues = [];
        $expectedFrom = 1;

        $checkpoints = $db->table('audit_checkpoints')
            ->where('generation', (int) $this->config->get('audit.generation'))
            ->orderBy('from_audit_id')
            ->get();

        foreach ($checkpoints as $checkpoint) {
            $from = (int) $checkpoint->from_audit_id;
            $to = (int) $checkpoint->to_audit_id;
            if ($from !== $expectedFrom) {
                $issues[] = $this->issue('chain_gap', 'critical', "checkpoint {$checkpoint->id} starts at $from, expected $expectedFrom");
            }
            $expectedFrom = $to + 1;

            $rows = $this->checkRows($from, $to, $issues);
            $count = count($rows);
            $expected = (int) $checkpoint->event_count;
            if ($count > $expected) {
                $issues[] = $this->issue('late_commit', 'high', "checkpoint {$checkpoint->id} ($from-$to): $count rows, $expected when closed");
            } elseif ($count < $expected) {
                $issues[] = $this->issue('rows_missing', 'critical', "checkpoint {$checkpoint->id} ($from-$to): $count rows, $expected when closed");
            } elseif ($this->hasher->merkleRoot($rows) !== $checkpoint->merkle_root) {
                $issues[] = $this->issue('root_mismatch', 'critical', "checkpoint {$checkpoint->id} ($from-$to): Merkle root differs");
            }
        }

        // Rows not yet closed into a checkpoint: their own hashes still have to match.
        $this->checkRows($expectedFrom, PHP_INT_MAX, $issues);

        return $issues;
    }

    /**
     * Streams the rows of an ID range, reports rows whose hash does not match, and returns IDs with stored hashes.
     *
     * @param  list<array{type: string, severity: string, detail: string}>  $issues
     * @return list<array{id: int, row_hash: string}>
     */
    private function checkRows(int $from, int $to, array &$issues): array
    {
        $rows = [];
        $query = $this->db->connection()->table('audit_events')->where('id', '>=', $from)->where('id', '<=', $to)->orderBy('id');
        foreach ($query->cursor() as $row) {
            $columns = (array) $row;
            if (! hash_equals((string) $columns['row_hash'], $this->hasher->rowHash($columns))) { // @phpstan-ignore argument.type (row read from audit_events)
                $issues[] = $this->issue('row_tampered', 'critical', "audit event {$columns['id']} ({$columns['ulid']}) does not match its hash");
            }
            $rows[] = ['id' => (int) $columns['id'], 'row_hash' => (string) $columns['row_hash']];
        }

        return $rows;
    }

    /**
     * @return array{type: string, severity: string, detail: string}
     */
    private function issue(string $type, string $severity, string $detail): array
    {
        return ['type' => $type, 'severity' => $severity, 'detail' => $detail];
    }
}
