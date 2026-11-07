<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\Platform\Events\AuditVerificationFailed;

/**
 * Checks the audit trail against the signed manifests in the audit-anchor bucket (P1-50, ADR-0006, docs/09 #18), not
 * against the checkpoint rows alone: someone who can rewrite both the rows and the checkpoints still cannot sign a
 * manifest or remove one from the locked bucket. Reports, never repairs:
 *
 * - manifest_invalid (critical): an object in the bucket that is not a manifest, whose signature does not verify with
 *   a trusted key, or whose content does not match its name
 * - row_tampered (critical): a row's stored hash does not match its columns
 * - rows_missing (critical): a signed range holds fewer rows than its manifest counted
 * - late_commit (high): a signed range holds more rows than counted; a transaction committed after the range closed
 *   (investigate per runbook, then audit:reanchor)
 * - root_mismatch (critical): same count, different Merkle root (rows replaced or IDs changed)
 * - chain_gap (critical): the signed ranges of a generation do not cover IDs contiguously from 1
 * - checkpoint_missing / checkpoint_mismatch (critical): a manifest has no checkpoint row, or the row differs from it
 * - manifest_missing (critical): a checkpoint recorded as exported has no valid manifest
 * - unanchored (high): a checkpoint still not exported after the stale window
 * - anchor_stale (high): rows older than the lag window plus the stale window are in no signed range
 *
 * For each range the highest valid revision counts (a supplementary checkpoint after a late commit).
 *
 * @phpstan-type Manifest array{v: int, generation: int, revision: int, from: int, to: int, count: int, root: string, watermark_at: string, closed_at: string, key_id: string}
 */
class AuditVerifier
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditHasher $hasher,
        private readonly AuditSigner $signer,
        private readonly FilesystemManager $storage,
        private readonly Config $config,
    ) {}

    /**
     * @return list<array{type: string, severity: string, detail: string}>
     */
    public function verify(): array
    {
        $this->signer->keyId(); // where there is no key there is no anchor-bucket key either: say so plainly
        $db = $this->db->connection();
        $generation = (int) $this->config->get('audit.generation');
        $issues = [];

        [$ranges, $signed] = $this->manifests($generation, $issues);

        $expectedFrom = 1;
        foreach ($ranges as $manifest) {
            $label = "manifest {$manifest['from']}-{$manifest['to']} r{$manifest['revision']}";
            if ($manifest['from'] !== $expectedFrom) {
                $issues[] = $this->issue('chain_gap', 'critical', "$label starts at {$manifest['from']}, expected $expectedFrom");
            }
            $expectedFrom = $manifest['to'] + 1;

            $rows = $this->rows($manifest['from'], $manifest['to'], $issues);
            $count = count($rows);
            if ($count > $manifest['count']) {
                $issues[] = $this->issue('late_commit', 'high', "$label: $count rows, {$manifest['count']} when signed");
            } elseif ($count < $manifest['count']) {
                $issues[] = $this->issue('rows_missing', 'critical', "$label: $count rows, {$manifest['count']} when signed");
            } elseif ($this->hasher->merkleRoot($rows) !== $manifest['root']) {
                $issues[] = $this->issue('root_mismatch', 'critical', "$label: Merkle root differs");
            }
        }

        // Checkpoint rows against every valid manifest, and checkpoints that were not (or not yet) exported.
        $staleBefore = CarbonImmutable::now('UTC')->subMinutes((int) $this->config->get('audit.anchor.stale_minutes'));
        $anchored = $db->table('audit_anchors')->pluck('checkpoint_id')->map(fn (mixed $id): int => (int) $id)->flip();
        $supplemented = $db->table('audit_checkpoints')->whereNotNull('supplements_id')->pluck('supplements_id')->map(fn (mixed $id): int => (int) $id)->flip();
        foreach ($db->table('audit_checkpoints')->where('generation', $generation)->orderBy('id')->cursor() as $checkpoint) {
            $key = "{$checkpoint->from_audit_id}/{$checkpoint->revision}";
            if (isset($signed[$key])) {
                if (AuditAnchor::manifest($checkpoint, $signed[$key]['key_id']) !== $signed[$key]) {
                    $issues[] = $this->issue('checkpoint_mismatch', 'critical', "checkpoint {$checkpoint->id} ($key) differs from its signed manifest");
                }
                unset($signed[$key]);
            } elseif ($anchored->has((int) $checkpoint->id)) {
                $issues[] = $this->issue('manifest_missing', 'critical', "checkpoint {$checkpoint->id} ($key) was exported, but has no valid manifest");
            } elseif (! $supplemented->has((int) $checkpoint->id) && CarbonImmutable::parse((string) $checkpoint->created_at, 'UTC')->lt($staleBefore)) {
                $issues[] = $this->issue('unanchored', 'high', "checkpoint {$checkpoint->id} ($key) is not exported yet");
            }
        }
        foreach ($signed as $key => $manifest) {
            $issues[] = $this->issue('checkpoint_missing', 'critical', "manifest $key ({$manifest['from']}-{$manifest['to']}) has no checkpoint row");
        }

        // Rows after the last signed range: their own hashes still have to match, and they must not wait too long.
        $this->rows($expectedFrom, PHP_INT_MAX, $issues);
        $dueBefore = $staleBefore->subMinutes((int) $this->config->get('audit.lag_minutes'))->format('Y-m-d H:i:s.u');
        $overdue = $db->table('audit_events')->where('id', '>=', $expectedFrom)->where('occurred_at', '<', $dueBefore)->count();
        if ($overdue > 0) {
            $issues[] = $this->issue('anchor_stale', 'high', "$overdue audit rows from before $dueBefore are in no signed manifest");
        }

        return $issues;
    }

    /**
     * verify(), and on any issue a critical log entry and AuditVerificationFailed (docs/10 §5: alerting listens to it).
     *
     * @return list<array{type: string, severity: string, detail: string}>
     */
    public function verifyAndReport(): array
    {
        $issues = $this->verify();
        if ($issues !== []) {
            Log::critical('Audit trail verification failed', ['issues' => $issues]);
            AuditVerificationFailed::dispatch($issues);
        }

        return $issues;
    }

    /**
     * Streams the rows of an ID range, reports rows whose hash does not match, and returns IDs with stored hashes.
     *
     * @param  list<array{type: string, severity: string, detail: string}>  $issues
     * @return list<array{id: int, row_hash: string}>
     */
    public function rows(int $from, int $to, array &$issues): array
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
     * Reads and checks every manifest of the generation. Returns the highest valid revision of each range, by first ID,
     * and every valid manifest keyed "from/revision".
     *
     * @param  list<array{type: string, severity: string, detail: string}>  $issues
     * @return array{0: list<Manifest>, 1: array<string, Manifest>}
     */
    private function manifests(int $generation, array &$issues): array
    {
        $disk = $this->storage->disk((string) $this->config->get('audit.anchor.disk'));
        $keys = $disk->files("g$generation");
        sort($keys, SORT_STRING);

        $ranges = [];
        $signed = [];
        foreach ($keys as $key) {
            $manifest = $this->signedManifest((string) $disk->get($key));
            if (is_string($manifest)) {
                $issues[] = $this->issue('manifest_invalid', 'critical', "$key: $manifest");

                continue;
            }
            if ($manifest['generation'] !== $generation || AuditAnchor::objectKey($generation, $manifest['from'], $manifest['to'], $manifest['revision']) !== $key) {
                $issues[] = $this->issue('manifest_invalid', 'critical', "$key: content does not match its name");

                continue;
            }

            $signed["{$manifest['from']}/{$manifest['revision']}"] = $manifest;
            if (! isset($ranges[$manifest['from']]) || $manifest['revision'] > $ranges[$manifest['from']]['revision']) {
                $ranges[$manifest['from']] = $manifest;
            }
        }
        ksort($ranges);

        return [array_values($ranges), $signed];
    }

    /**
     * The manifest in an exported object if it is well formed and signed by a trusted key; otherwise why not.
     *
     * @return Manifest|string
     */
    private function signedManifest(string $body): array|string
    {
        try {
            $object = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'not JSON';
        }
        $manifest = is_array($object) ? ($object['manifest'] ?? null) : null;
        $signature = is_array($object) ? ($object['signature'] ?? null) : null;
        $types = ['v' => 'integer', 'generation' => 'integer', 'revision' => 'integer', 'from' => 'integer', 'to' => 'integer', 'count' => 'integer',
            'root' => 'string', 'watermark_at' => 'string', 'closed_at' => 'string', 'key_id' => 'string'];
        if (! is_array($manifest) || ! is_string($signature) || count($object) !== 2 || array_keys($manifest) !== array_keys($types)
            || array_map(gettype(...), $manifest) !== $types || $manifest['v'] !== AuditAnchor::MANIFEST_VERSION) {
            return 'not a manifest of this version';
        }
        /** @var Manifest $manifest */
        if (! $this->signer->verify(AuditAnchor::encode($manifest), $manifest['key_id'], $signature)) {
            return 'signature does not verify with a trusted key';
        }

        return $manifest;
    }

    /**
     * @return array{type: string, severity: string, detail: string}
     */
    private function issue(string $type, string $severity, string $detail): array
    {
        return ['type' => $type, 'severity' => $severity, 'detail' => $detail];
    }
}
