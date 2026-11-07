# ADR-0006 — Append-only audit with database grants and signed, externally anchored checkpoints

- **Status:** Accepted, 2026-10-31. **Amended 2026-10-31** after Codex review TASK-0001 (findings #8, #9). **Implemented 2026-11-07** (P1-50, PO decisions O43–O45; see "As built" below).

## Context
Audit events must be immutable (`skills/06`) and tamper-evident. A sequential hash chain (each row hashes the previous one) serializes every audited write through one lock, which hurts concurrency. The Codex review showed three gaps in the first version of this ADR:
1. Checkpoints over raw ID ranges break when transactions commit out of ID order.
2. Redacting IP addresses would change hashed rows.
3. Checkpoints stored only in the same database do not resist an attacker who can write to that database.

## Decision
- `audit_events` is written by `AuditLogger` inside the same DB transaction as the business change. If the change rolls back, so does the audit row.
- The application DB user has `SELECT, INSERT` only on audit and other append-only tables (`docs/02` §6). Schema changes use a separate migrator user.
- Each row stores `row_hash` = SHA-256 over its **immutable** fields. Personal network data (IP, user-agent hash) lives in `audit_event_network`, which is not hashed and is deleted at end of retention. Hashed rows are never modified.
- **Checkpoints:** a scheduled job closes ID ranges only for rows older than a **lag window** (default 15 min). Business transactions are capped well below that duration and monitored. Each checkpoint records range, event count, Merkle root, watermark, and generation.
- **Trust anchor:** each checkpoint manifest is signed with an Ed25519 key (libsodium) that is not stored in the database. The manifest is exported to a dedicated **audit-anchor** bucket with its own credentials and compliance-mode object lock; production replicates it offsite. Verification compares the database to the **exported signed manifests**.
- **Late commits:** a row that appears inside an already-closed range causes a count mismatch. It is reported as `late_commit` (high severity, investigated by runbook) and anchored by a supplementary checkpoint. It is never silently absorbed.
- Key rotation: new key ID per rotation; old public keys kept for verification; procedure in the runbook (`docs/10` §6).
- Context is allowlisted per action. Secrets, tokens, and raw prompts are never stored.

## Consequences
- No write contention.
- Tampering by a database-level attacker is detectable unless they also control the signing key **and** the locked anchor bucket.
- Auto-increment gaps from rolled-back transactions are normal and are not treated as tampering.
- Running costs: one extra bucket, a signing key secret, and a verification job.

## Alternatives considered
- Strict hash chain: stronger ordering proof; rejected for write contention.
- Nulling IP columns in place: rejected; it breaks hashes and needs an UPDATE grant.
- External SIEM/WORM log store: a good later addition (Phase 6); the signed-manifest export provides the independent anchor now.

## As built (P1-50, 2026-11-07)
- **Who holds the key:** a separate `auditor` service (PO decision O43) is the only holder of the Ed25519 seed and the audit-anchor bucket key. It closes, signs and exports checkpoints every five minutes and verifies hourly (`audit:work`); the web, Horizon and scheduler containers hold neither key.
- **Where the signature is recorded:** in an append-only `audit_anchors` row written after each export, not on the checkpoint row (which cannot be updated once inserted). A checkpoint not yet exported is exported by the next run, after its rows are checked again; this also signed the checkpoints closed before P1-50 (O45).
- **Lock mode:** compliance for 7 years in production, enforced by the app; governance for one day locally and in CI (O44).
- **Late commits:** re-anchored by a supplementary checkpoint (`revision` > 1, `supplements_id`), only by the `audit:reanchor` command with a reason, after the runbook investigation (`docs/10` §6). Deletions and replaced rows are never re-anchored.
- **Transaction cap:** web requests end after 120 s (FPM `request_terminate_timeout`) and queued jobs after their Horizon timeout; any transaction that still commits after 300 s is logged critically. Commands are not cut off.
- **Rotation:** a new seed; earlier public keys stay trusted through `AUDIT_TRUSTED_KEYS`; the generation does not change.
