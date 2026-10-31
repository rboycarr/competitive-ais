# ADR-0006 — Append-only audit with database grants and signed, externally anchored checkpoints

- **Status:** Accepted, 2026-10-31. **Amended 2026-10-31** after Codex review TASK-0001 (findings #8, #9).

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
