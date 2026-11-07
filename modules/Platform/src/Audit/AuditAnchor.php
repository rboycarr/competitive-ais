<?php

namespace Modules\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemManager;
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
 * P1-50, PO decision O45). A late commit is re-anchored on purpose, by a supplementary revision (reanchor()).
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
     * Signs and exports, in ID order, every checkpoint of the generation that has no anchor yet and has not been
     * supplemented. Stops at the first one whose rows no longer match it.
     *
     * @phpstan-impure
     */
    public function anchorPending(): int
    {
        $pending = $this->db->connection()->table('audit_checkpoints as c')
            ->where('c.generation', $this->generation())
            ->whereNotExists(fn ($query) => $query->from('audit_anchors as a')->whereColumn('a.checkpoint_id', 'c.id'))
            ->whereNotExists(fn ($query) => $query->from('audit_checkpoints as s')->whereColumn('s.supplements_id', 'c.id'))
            ->orderBy('c.id')
            ->get(['c.*']);

        foreach ($pending as $checkpoint) {
            $this->export($checkpoint);
        }

        return $pending->count();
    }

    /**
     * Re-anchors a range after a late commit (docs/10 §6 runbook): a supplementary checkpoint of the same range, with
     * the rows it holds now, signed and exported. Refused unless every row matches its hash and the range holds more
     * rows than its latest checkpoint counted, so a deletion or a replaced row is never re-anchored. Whether the extra
     * rows are genuine late commits is the operator's finding (runbook), stated in $reason and audited.
     *
     * @return array{from: int, to: int, revision: int, count: int, previous_count: int}
     */
    public function reanchor(int $from, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Give the reason for re-anchoring (the investigation that found a late commit).');
        }
        $this->signer->keyId();
        $this->lockMode();
        $generation = $this->generation();

        $result = $this->db->connection()->transaction(function () use ($from, $reason, $generation): array {
            $db = $this->db->connection();
            // No row lock (the app account may only read and insert here): a concurrent re-anchor of the same range
            // fails on the unique (generation, from_audit_id, revision) key.
            $latest = $db->table('audit_checkpoints')->where('generation', $generation)->where('from_audit_id', $from)->orderByDesc('revision')->first();
            if ($latest === null) {
                throw new InvalidArgumentException("No checkpoint of generation $generation starts at audit ID $from.");
            }

            $issues = [];
            $rows = $this->verifier->rows($from, (int) $latest->to_audit_id, $issues);
            if ($issues !== []) {
                throw new RuntimeException('Rows in this range do not match their hashes. Only a late commit is re-anchored; investigate (audit:verify).');
            }
            $count = count($rows);
            $previous = (int) $latest->event_count;
            if ($count <= $previous) {
                throw new RuntimeException("Nothing to re-anchor: the range holds $count rows and its checkpoint counted $previous. Only a late commit (more rows) is re-anchored.");
            }

            $now = CarbonImmutable::now('UTC');
            $revision = (int) $latest->revision + 1;
            $db->table('audit_checkpoints')->insert([
                'generation' => $generation,
                'from_audit_id' => $from,
                'to_audit_id' => (int) $latest->to_audit_id,
                'revision' => $revision,
                'supplements_id' => (int) $latest->id,
                'event_count' => $count,
                'merkle_root' => $this->hasher->merkleRoot($rows),
                'watermark_at' => $latest->watermark_at,
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

            $result = ['from' => $from, 'to' => (int) $latest->to_audit_id, 'revision' => $revision, 'count' => $count, 'previous_count' => $previous];
            $this->logger->record('audit.reanchored', null, ['generation' => $generation] + $result + ['reason' => $reason], Actor::system());

            return $result;
        });

        $this->anchorPending();

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

        LockedObjects::write($this->disk(), $key, $body, 'application/json', $now->addDays($this->retentionDays()), $this->lockMode());

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
