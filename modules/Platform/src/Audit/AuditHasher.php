<?php

namespace Modules\Platform\Audit;

use JsonException;

/**
 * Row hashes and Merkle roots for the audit trail (ADR-0006).
 *
 * Row hash: SHA-256 over a versioned, canonical JSON serialization of the immutable columns, in a fixed order.
 * Context keys are sorted; context values are limited to strings, integers, booleans, null and lists of those
 * (AuditLogger), so the serialization survives the round trip through MySQL's JSON type unchanged.
 *
 * Merkle root: RFC 6962 tree hash over the rows in ID order; each leaf binds the row ID to its hash, so removing,
 * adding or reordering rows changes the root.
 */
class AuditHasher
{
    public const VERSION = 1;

    /**
     * @param  array{ulid: string, occurred_at: string, actor_type: string, actor_id: int|string|null, action: string,
     *     subject_type: ?string, subject_id: int|string|null, subject_ulid: ?string, classification: string,
     *     request_id: ?string, context: array<string, mixed>|string|null}  $row  as inserted, or as read back
     *
     * @throws JsonException
     */
    public function rowHash(array $row): string
    {
        $context = is_string($row['context']) ? json_decode($row['context'], true, 16, JSON_THROW_ON_ERROR) : $row['context'];

        $canonical = [
            'v' => self::VERSION,
            'ulid' => $row['ulid'],
            'occurred_at' => $row['occurred_at'],
            'actor_type' => $row['actor_type'],
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'action' => $row['action'],
            'subject_type' => $row['subject_type'],
            'subject_id' => $row['subject_id'] === null ? null : (int) $row['subject_id'],
            'subject_ulid' => $row['subject_ulid'],
            'classification' => $row['classification'],
            'request_id' => $row['request_id'],
            'context' => is_array($context) && $context !== [] ? $this->sortKeys($context) : null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{id: int, row_hash: string}>  $rows  in ascending ID order
     */
    public function merkleRoot(array $rows): string
    {
        $leaves = array_map(
            fn (array $row): string => hash('sha256', "\x00".pack('J', $row['id']).hex2bin($row['row_hash']), true),
            $rows,
        );

        return bin2hex($this->treeHash($leaves));
    }

    /**
     * @param  list<string>  $nodes  binary hashes
     */
    private function treeHash(array $nodes): string
    {
        $count = count($nodes);
        if ($count === 0) {
            return hash('sha256', '', true);
        }
        if ($count === 1) {
            return $nodes[0];
        }

        $split = 1;
        while ($split * 2 < $count) {
            $split *= 2;
        }

        return hash('sha256', "\x01".$this->treeHash(array_slice($nodes, 0, $split)).$this->treeHash(array_slice($nodes, $split)), true);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? $this->sortKeys($item) : $item, $value);
    }
}
