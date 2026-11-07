# TASK-0094 — Codex review of P1-48 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b38d-75ab-7210-adfc-5f8b8ed11dbc |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0094.prompt.md`, branch `feature/rereview-flags` @ `fe35d15` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `ad8a43fc8f4c4da55a16cb777aa638e71f67063fb28d37281e03c5c8287afb31` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — deadlock from inconsistent lock order**

   **Files/lines:**  
   - `modules/Evidence/src/Actions/EvidenceIntegrityService.php:55–57`  
   - `modules/Evidence/src/Actions/EvidenceWithdrawalService.php:54–65`  
   - `modules/Evidence/src/Actions/EvidenceScanService.php:224–231`  
   - `modules/Evidence/src/Actions/EvidenceBlobReconciler.php:74–77, 114–119`

   The new paths lock asset → blob, while `EvidenceBlobReconciler` locks blob → asset. Concurrent operations can deadlock:

   - reconciliation holds the blob lock and waits for an asset;
   - withdrawal, scanning, or integrity verification holds that asset and waits for the blob.

   **Safe fix:** standardize all multi-row operations on one order. Prefer asset IDs in ascending order, then blob. Refactor reconciliation to acquire affected asset locks before the blob, or ensure callers never invoke reconciliation while holding the asset lock and add a consistently ordered helper. Add a concurrent deadlock regression test.

2. **Medium — scanning can attach an asset to a permanently mismatched blob**

   **File/lines:** `modules/Evidence/src/Actions/EvidenceScanService.php:224–243`

   Integrity verification can mark a blob `mismatch` while a scan is outside its transaction. The scan then acquires the asset and blob locks and attaches the blob without checking `integrity_status`. The resulting asset can have `status = available` while referencing a blob known to be mismatched.

   Although linking excludes the blob, this violates the stated invariant that available evidence is intact and can create inconsistent download/custody behavior.

   **Safe fix:** after locking the blob, refuse attachment unless `integrity_status = 'ok'`; transition the asset to a safe terminal/rejected state and record the appropriate custody/audit result. Add a race test where integrity failure commits before scan finalization.

3. **Medium — integrity verification does not preserve the requested version on the fake disk**

   **File/lines:** `modules/Platform/src/Storage/LockedObjects.php:135–143`

   The S3 path requires and reads the exact `VersionId`, but the testing/fake-disk path ignores `$versionId` and reads the current key. This can make tests pass while production verifies a different stored version than the test models.

   **Safe fix:** make the fake storage represent versioned objects, or explicitly document and test the version mapping. At minimum, reject a null/empty version consistently and add tests proving the requested version is the one hashed.

4. **Low — no explicit validation that the outbox payload asset matches the aggregate**

   **File/lines:** `modules/Intelligence/src/Outbox/FlagCitingItems.php:39–46`

   The handler trusts `aggregate_id` for database targeting and independently trusts `payload['evidence_ulid']` for audit output. A malformed or corrupted event could flag one asset while recording another ULID in the audit event.

   **Safe fix:** load the aggregate asset and verify its ULID equals the payload ULID before processing; reject the event on mismatch. Add an invalid-payload test.

## Missing tests

- Concurrent reconciler versus withdrawal, scan, and integrity verification; assert no deadlock and correct final state.
- Integrity failure racing with scan finalization.
- Integrity verification of an S3 version where the current object differs from the recorded version.
- Missing-version and missing-object classification for S3, including non-`NoSuchKey`/`NoSuchVersion` errors.
- Large-object streaming and stream-close behavior.
- At-least-once delivery after handler completion but before receipt persistence.
- Flagging when a relation is `contradicts` or `context`, and exclusion when the claim is withdrawn.
- Visibility tests for a viewer who can see the intelligence item but not the flagged evidence, including provenance audit contexts.
- Clearing by the approving reviewer, author/owner refusal, confidential-item director requirement, and later re-flagging after a prior clear.
- Download refusal for an asset whose blob is marked `mismatch`.
- Migration rollback with flags, clear-review rows, and legacy `flag_for_rereview` rows.

## Answers to the brief’s review questions and design calls

- **Concurrency and locks:** Asset → blob is consistent among withdrawal, scanning, and integrity verification, but conflicts with the existing reconciler’s blob → asset order. This is a merge-blocking concurrency defect. Item locking in the handler and workflow is generally appropriate and ID-ordered.
- **Leakage:** The banner and provenance name evidence only when the viewer can see it; otherwise they expose a generic hidden-evidence message. The Vue output is escaped. I found no obvious evidence-title, reason, SHA-256, or item leakage through the reviewed paths.
- **Integrity correctness:** S3 error classification and streaming are appropriately narrow and streaming-based. The fake-disk version behavior is weaker than production and needs a faithful test abstraction.
- **Outbox:** Receipt-based idempotency is sound. The handler’s unique constraint and status re-check protect duplicate delivery and concurrent workflow changes. Provider definition/subscription ordering is logically correct because Evidence defines the event and Intelligence subscribes.
- **Migration:** The rollback refusal for flags and clearances is appropriate. The CHECK replacement preserves the existing `flag_for_rereview` value and adds `clear_rereview` correctly.
- **Design call 1:** Separation of duties is implemented correctly; the approving reviewer may clear, while author/owner cannot.
- **Design call 2:** The clearing guard correctly requires every non-withdrawn claim to retain visible, available, intact support.
- **Design call 3:** Flagging approved/published items through any relation from non-withdrawn claims matches the brief.
- **Design call 4:** Reusing `evidence.hold` and refusing withdrawal during legal hold matches the PO decision.
- **Design calls 5–7:** No deletion, final integrity marking, and asynchronous delivery are implemented consistently with the brief.

## Recommendation

**Yes after fixes.**

The branch should not merge until the lock-order deadlock is resolved and the scan-versus-integrity race is closed. The remaining concerns are test-hardening and fake-storage fidelity.

## What I did not check

I did not execute tests, migrations, static analysis, JavaScript checks, the application, the scheduler, the outbox relay, or any network/storage operations. I reviewed only repository contents and read-only Git output. I did not open `.env`, other ignored environment files, or `.secrets/`.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log only quotes the rule). Recommendation "yes after fixes".

| # | Finding | Disposition |
|---|---|---|
| 1 | High: deadlock, the reconciler locks blob then asset | **Not a defect; documented.** `EvidenceBlobReconciler` locks only the blob row (`reconcile`, `deletable`); it reads the blob's assets with plain, non-locking reads and never waits for an asset lock, so no blob→asset wait exists to close a cycle with the asset→blob order of holds, scans, withdrawals and the re-hash. The class docblock now says so, and any future asset lock there must come first |
| 2 | Medium: a scan can attach an asset to a blob already marked `mismatch` | **Fixed.** `EvidenceScanService::decide` checks the blob's integrity under its lock; a clean upload whose bytes match a blob marked `mismatch` is rejected (`stored_copy_failed`: custody `scanned` and `rejected`, audit `evidence.rejected`), never made available on a copy known to be bad. Repairing such a blob from good bytes is not built. Test: a clean scan of the same bytes after the mark |
| 3 | Medium: the fake disk ignores the version | **Accepted, documented.** The fallback exists only in tests (refused outside `testing`), where blobs written through the fakes have no version. The S3 path reads exactly the recorded `VersionId` (asserted in `EvidenceWithdrawalTest`), and a missing or empty version is refused there |
| 4 | Low: the payload's asset is not checked against the aggregate | **Fixed.** `FlagCitingItems` resolves the aggregate's ULID through a new IDs-only Evidence contract method (`EvidenceLinking::ulids`) and refuses the event when it differs from the payload (the delivery fails, retries, then dead-letters). Test added |
| — | Suggested tests | Added: contradicts and context links flagged (withdrawn claims were already excluded); the approving reviewer may clear; confidential items cleared only by the Director role; a payload naming another asset. Already there: download refused for a blob marked `mismatch` (`EvidenceDownloadTest`, since P1-34), re-flagging after a clear, hidden evidence in the banner and provenance (with flag ULIDs shown in audit contexts), rollback over flags and clearances. Not added: multi-process deadlock tests for paths that take no conflicting locks (#1); receipt-loss redelivery (the relay's own `OutboxTest` covers it, and the handler is idempotent on the unique key and the existence check) |
