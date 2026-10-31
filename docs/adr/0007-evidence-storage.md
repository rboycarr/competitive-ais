# ADR-0007 — Evidence storage: quarantine, scan, content-addressed blobs, object lock

- **Status:** Accepted, 2026-10-31
- **Amended 2026-10-31 (O17, Product Owner):** MinIO is archived and no longer publishes images, so the object store is **SeaweedFS** (S3 gateway). The design is unchanged (S3 API, versioning, object lock). Wherever this ADR or other docs say "MinIO", read "the S3-compatible object store (SeaweedFS)".

### Amendment 2026-10-31 — SeaweedFS as the object store (O17)
- Chosen after a conformance spike (`scripts/spikes/o17-object-store/`): versioning, delete markers, governance and compliance retention, legal hold, bucket default retention, server-side SHA-256 checksums, pre-signed URL expiry and tamper rejection, anonymous access denied, and locks persisting across restart. SeaweedFS 4.51 and RustFS 1.0.4 both passed 33/33; SeaweedFS was preferred for its longer production record and identities with per-bucket permissions declared in a config file that can be reviewed.
- SeaweedFS reports usage telemetry by default. It always runs with `-master.telemetry=false`, and the data network has no internet egress.
- Identities are declared in the S3 identity config: the application key gets read/write on `evidence-quarantine`, `evidence`, and `exports` only, without governance bypass; the audit-anchor writer gets its own key limited to `audit-anchor`; the admin identity that can bypass governance is not given to the application. P1-02 must prove this with tests (denied bypass, denied cross-bucket access) before the store is pinned.
- **Verified 2026-10-31 (P1-02):** `scripts/dev/verify-object-store.sh` passes 19/19 against the running stack. In SeaweedFS, object-lock operations need their own permissions; `Write` does not include them. The app identity therefore also holds `GetObjectRetention`, `PutObjectRetention`, `GetObjectLegalHold`, and `PutObjectLegalHold` on `evidence` only, and never `BypassGovernanceRetention`. Without the retention permission, even a PUT that carries lock headers is denied. Identity template: `docker/seaweedfs/s3-identities.template.json`; keys are generated into the gitignored `.env` / `.env.services` by `scripts/dev/setup.sh`.

## Context
Evidence must be preserved with SHA-256 hashes, MIME, size, storage key, and chain of custody (`competitive-ais.md` §6). Uploads are untrusted (`docs/07` T7). The same file is often captured more than once from different sources.

## Decision
- Uploads stream to a private `evidence-quarantine` bucket. A queued job scans with ClamAV, computes SHA-256 server-side, and detects MIME by magic bytes.
- Clean files are stored once in the private `evidence` bucket at `sha256/<2>/<2>/<hash>` (`evidence_blobs`, UNIQUE sha256). Each capture is its own `evidence_assets` row with source, URL, capture time, classification, and uploader. Infected or disallowed files are rejected and their quarantine objects deleted.
- The `evidence` bucket has versioning and **object lock in governance mode**. Retention and legal hold map to object retention and legal-hold settings.
- Custody events (`evidence_custody_events`, append-only) record upload, scan, view, download, link, and hold changes.
- Downloads go through a policy-checked controller that issues ≤60 s pre-signed URLs with `Content-Disposition: attachment`.

### Amendment 2026-10-31 (Codex review TASK-0001, findings #1, #5, #7, #10)
- `evidence_blobs` holds **only content that scanned clean**. Scanning happens per asset on its quarantine object, so an infected or failed scan can never touch a shared blob. Scan status is monotonic.
- Blob creation is an atomic insert-or-reload on `sha256`. The object copy to its content-addressed key is idempotent.
- Blob retention = MAX of referencing assets' retention; blob legal hold = OR of their holds. Both are recomputed under a row lock and pushed to the MinIO object version (`object_version_id` stored). Deletion jobs recheck references, retention, and hold under lock.
- Assets record the `source_policy_id` in force at capture and carry competitor/market scopes (declared + inherited on link).
- Status values: quarantined, available, rejected, withdrawn. Legal hold is a flag, not a status. A withdrawn or integrity-failed asset flags citing items for re-review.

## Consequences
- Storage dedupes and the hash doubles as an integrity check (periodic re-hash job).
- A blob may back assets with different classifications. Visibility is evaluated per **asset**, never per blob. Blob deletion happens only when no assets reference it and no retention or hold applies.
- Governance mode can be overridden by a privileged MinIO identity for lawful deletion. That identity is not given to the application.

## Alternatives considered
- Store files per asset (no dedupe): simpler; wastes storage and weakens duplicate detection.
- Compliance-mode object lock: stronger, but makes lawful deletion requests impossible before expiry.
