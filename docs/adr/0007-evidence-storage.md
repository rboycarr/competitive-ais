# ADR-0007 — Evidence storage: quarantine, scan, content-addressed blobs, object lock

- **Status:** Accepted, 2026-10-31
- **Open (2026-10-31):** MinIO, named below as the object store, is archived and no longer publishes images. The design stays (S3 API, versioning, object lock); the product is chosen under O17 (`docs/11` §5) and recorded here. Read "MinIO" below as "the S3-compatible object store" until then.

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
