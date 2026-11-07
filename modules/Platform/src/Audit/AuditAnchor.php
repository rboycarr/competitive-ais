<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Platform\Database\UniqueViolation;
use Modules\Platform\Storage\LockedObjects;
use RuntimeException;
use stdClass;

/**
 * Signs audit checkpoints and exports their manifests to the audit-anchor bucket (P1-50, ADR-0006, docs/02 §3.1).
 *
 * A manifest states a checkpoint's generation, revision, ID range, event count, Merkle root, watermark and closing
 * time, and the signing key's ID; the stored object is that manifest with its Ed25519 signature, under object lock
 * (compliance mode in production, PO decision O44). The export is recorded in `audit_anchors` after the write, so a
 * failed export is retried by the next run with the same bytes. A checkpoint is signed only while its rows still match
 * it, so neither a tampered range nor a late commit is ever signed (this also covers the checkpoints closed before
 * P1-50, PO decision O45). A late commit is re-anchored on purpose, by a supplementary revision (reanchor()), and only
 * there: the routine export signs first revisions that continue the signed ranges (TASK-0096 #1, #3).
 */
class AuditAnchor
{
    public const MANIFEST_VERSION = 1;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AuditHasher $hasher,
        private readonly AuditSigner $signer,
        private readonly AuditCheckpointer $checkpointer,
        private readonly AuditVerifier $verifier,
        private readonly AuditLogger $logger,
        private readonly FilesystemManager $storage,
        private readonly Config $config,
    ) {}

    /**
     * Exports any checkpoint not yet anchored, closes the next due range, and exports it.
     *
     * @return array{anchored: int, checkpoint: array{from: int, to: int, count: int, root: string}|null}
     *
     * @phpstan-impure
     */
    public function checkpoint(): array
    {
        $this->signer->keyId(); // where there is no key, nothing is closed that could not be signed
        $this->lockMode();

        $anchored = $this->anchorPending();
        $checkpoint = $this->checkpointer->close();
        if ($checkpoint !== null) {
            $anchored += $this->anchorPending();
        }

        return ['anchored' => $anchored, 'checkpoint' => $checkpoint];
    }

    /**
     * Signs and exports, in ID order, every first-revision checkpoint of the generation that has no anchor yet and has
     * not been supplemented. Stops at the first one whose rows no longer match it, or that does not continue the signed
     * ranges (a checkpoint row someone inserted over a signed range; TASK-0096 #3). Supplementary revisions are never
     * signed here, only by reanchor(), so a forged one inserted into the table stays unsigned (TASK-0096 #1).
     *
     * @phpstan-impure
     */
    public function anchorPending(): int
    {
        $db = $this->db->connection();
        $pending = $db->table('audit_checkpoints as c')
            ->where('c.generation', $this->generation())
            ->where('c.revision', 1)
            ->whereNotExists(fn ($query) => $query->from('audit_anchors as a')->whereColumn('a.checkpoint_id', 'c.id'))
            ->whereNotExists(fn ($query) => $query->from('audit_checkpoints as s')->whereColumn('s.supplements_id', 'c.id'))
            ->orderBy('c.id')
            ->get(['c.*']);

        foreach ($pending as $checkpoint) {
            $signedTo = (int) $db->table('audit_checkpoints as c')->join('audit_anchors as a', 'a.checkpoint_id', '=', 'c.id')
                ->where('c.generation', $this->generation())->max('c.to_audit_id');
            if ((int) $checkpoint->from_audit_id !== $signedTo + 1) {
                throw new RuntimeException("Checkpoint {$checkpoint->id} starts at audit ID {$checkpoint->from_audit_id}, but the signed ranges end at $signedTo, so it is not signed. Run audit:verify and follow the runbook (docs/10 §6).");
            }
            $this->export($checkpoint);
        }

        return $pending->count();
    }

    /**
     * Re-anchors a signed range after a late commit (docs/10 §6 runbook): a supplementary checkpoint of the same range,
     * signed and exported. The operator names the late rows ($lateIds, from the investigation). The other rows must be
     * exactly the ones the range's latest signed manifest in the bucket counted (same count and Merkle root without the
     * late rows), and every row must match its hash: so a deletion, a replaced row, or a row the operator did not name
     * is never re-anchored, and nothing in the database alone can make it so (TASK-0096 #1). The reason and the late IDs
     * are audited. If an earlier run committed the supplement but failed to export it, this run exports it.
     *
     * @param  list<int>  $lateIds
     * @return array{from: int, to: int, revision: int, count: int, previous_count: int}
     */
    public function reanchor(int $from, array $lateIds, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Give the reason for re-anchoring (the investigation that found a late commit).');
        }
        $lateIds = array_values(array_unique($lateIds));
        sort($lateIds);
        if ($lateIds === []) {
            throw new InvalidArgumentException('Name the late-committed audit IDs the investigation found (--late=ID,ID).');
        }
        $this->signer->keyId();
        $this->lockMode();
        $generation = $this->generation();

        $signed = $this->verifier->signedRange($generation, $from);
        if ($signed === null) {
            throw new InvalidArgumentException("No signed manifest of generation $generation starts at audit ID $from; only a signed range is re-anchored.");
        }
        $manifest = $signed['manifest'];

        $issues = [];
        $rows = $this->verifier->rows($from, $manifest['to'], $issues);
        if ($issues !== []) {
            throw new RuntimeException('Rows in this range do not match their hashes. Only a late commit is re-anchored; investigate (audit:verify).');
        }
        $ids = array_column($rows, 'id');
        if (array_diff($lateIds, $ids) !== []) {
            throw new RuntimeException('Some of the late IDs are not rows of this range.');
        }
        $others = array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['id'], $lateIds, true)));
        if (count($others) !== $manifest['count'] || $this->hasher->merkleRoot($others) !== $manifest['root']) {
            throw new RuntimeException('Without the late IDs, the range does not match its signed manifest (r'.$manifest['revision'].'). Only a late commit, with every late row named, is re-anchored.');
        }

        $result = ['from' => $from, 'to' => $manifest['to'], 'revision' => $manifest['revision'] + 1, 'count' => count($rows), 'previous_count' => $manifest['count']];
        $root = $this->hasher->merkleRoot($rows);

        $db = $this->db->connection();
        $supplement = $db->table('audit_checkpoints')->where('generation', $generation)->where('from_audit_id', $from)->where('revision', $result['revision'])->first();
        if ($supplement === null) {
            $previous = $db->table('audit_checkpoints')->where('generation', $generation)->where('from_audit_id', $from)->where('revision', $manifest['revision'])->first();
            if ($previous === null) {
                throw new RuntimeException("The signed revision {$manifest['revision']} of this range has no checkpoint row; investigate (audit:verify).");
            }
            $db->transaction(function () use ($db, $generation, $result, $root, $previous, $lateIds, $reason): void {
                $db->table('audit_checkpoints')->insert([
                    'generation' => $generation,
                    'from_audit_id' => $result['from'],
                    'to_audit_id' => $result['to'],
                    'revision' => $result['revision'],
                    'supplements_id' => (int) $previous->id,
                    'event_count' => $result['count'],
                    'merkle_root' => $root,
                    'watermark_at' => $previous->watermark_at,
                    'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
                $this->logger->record('audit.reanchored', null, ['generation' => $generation] + $result + ['late_ids' => $lateIds, 'reason' => $reason], Actor::system());
            });
            $supplement = $db->table('audit_checkpoints')->where('generation', $generation)->where('from_audit_id', $from)->where('revision', $result['revision'])->sole();
        } elseif ((int) $supplement->event_count !== $result['count'] || $supplement->merkle_root !== $root
            || $db->table('audit_anchors')->where('checkpoint_id', $supplement->id)->exists()) {
            throw new RuntimeException("Revision {$result['revision']} of this range already exists and differs or is exported; investigate (audit:verify).");
        }

        $this->export($supplement);

        return $result;
    }

    /**
     * The manifest of a checkpoint row, as signed. Field order is fixed: the signature covers encode() of this array.
     *
     * @return array{v: int, generation: int, revision: int, from: int, to: int, count: int, root: string, watermark_at: string, closed_at: string, key_id: string}
     */
    public static function manifest(stdClass $checkpoint, string $keyId): array
    {
        return [
            'v' => self::MANIFEST_VERSION,
            'generation' => (int) $checkpoint->generation,
            'revision' => (int) $checkpoint->revision,
            'from' => (int) $checkpoint->from_audit_id,
            'to' => (int) $checkpoint->to_audit_id,
            'count' => (int) $checkpoint->event_count,
            'root' => (string) $checkpoint->merkle_root,
            'watermark_at' => (string) $checkpoint->watermark_at,
            'closed_at' => (string) $checkpoint->created_at,
            'key_id' => $keyId,
        ];
    }

    /** @param  array<string, mixed>  $value */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Sorts by generation, first ID, last ID, then revision. */
    public static function objectKey(int $generation, int $from, int $to, int $revision): string
    {
        return sprintf('g%d/%020d-%020d-r%05d.json', $generation, $from, $to, $revision);
    }

    public function disk(): Filesystem
    {
        return $this->storage->disk((string) $this->config->get('audit.anchor.disk'));
    }

    private function export(stdClass $checkpoint): void
    {
        $from = (int) $checkpoint->from_audit_id;
        $to = (int) $checkpoint->to_audit_id;
        $issues = [];
        $rows = $this->verifier->rows($from, $to, $issues);
        if ($issues !== [] || count($rows) !== (int) $checkpoint->event_count || $this->hasher->merkleRoot($rows) !== $checkpoint->merkle_root) {
            throw new RuntimeException("Checkpoint {$checkpoint->id} (audit IDs $from-$to, revision {$checkpoint->revision}) no longer matches its rows, so it is not signed. Run audit:verify and follow the runbook (docs/10 §6).");
        }

        $keyId = $this->signer->keyId();
        $manifest = self::manifest($checkpoint, $keyId);
        $signature = $this->signer->sign(self::encode($manifest));
        $body = self::encode(['manifest' => $manifest, 'signature' => $signature])."\n";
        $key = self::objectKey($manifest['generation'], $from, $to, $manifest['revision']);
        $now = CarbonImmutable::now('UTC');

        $disk = $this->disk();
        if ($disk->exists($key) && $disk->get($key) === $body) {
            // Exported before, but its record is missing: the write succeeded and the insert below did not, or someone
            // deleted the record (TASK-0096 #2). The same bytes are already locked; record them again, visibly.
            Log::warning('Audit manifest already exported; recording its anchor again', ['checkpoint' => (int) $checkpoint->id, 'object_key' => $key]);
        } else {
            LockedObjects::write($disk, $key, $body, 'application/json', $now->addDays($this->retentionDays()), $this->lockMode());
        }

        try {
            $this->db->connection()->table('audit_anchors')->insert([
                'checkpoint_id' => (int) $checkpoint->id,
                'key_id' => $keyId,
                'signature' => $signature,
                'object_key' => $key,
                'manifest_sha256' => hash('sha256', $body),
                'anchored_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent run exported the same checkpoint first (Ed25519 is deterministic: the same bytes).
            if (! UniqueViolation::on($e, 'audit_anchors', 'audit_anchors_checkpoint_id_unique')) {
                throw $e;
            }
        }
    }

    /** @return 'GOVERNANCE'|'COMPLIANCE' */
    private function lockMode(): string
    {
        $mode = strtoupper((string) $this->config->get('audit.anchor.lock_mode'));
        if ($mode !== 'GOVERNANCE' && $mode !== 'COMPLIANCE') {
            throw new RuntimeException('AUDIT_ANCHOR_LOCK_MODE must be COMPLIANCE or GOVERNANCE.');
        }
        if ($mode !== 'COMPLIANCE' && app()->isProduction()) {
            throw new RuntimeException('In production, audit manifests are locked in compliance mode (PO decision O44).');
        }

        return $mode;
    }

    private function retentionDays(): int
    {
        $days = (int) $this->config->get('audit.anchor.retention_days');
        if ($days < 1) {
            throw new RuntimeException('AUDIT_ANCHOR_RETENTION_DAYS must be at least 1.');
        }

        return $days;
    }

    private function generation(): int
    {
        return (int) $this->config->get('audit.generation');
    }
}
