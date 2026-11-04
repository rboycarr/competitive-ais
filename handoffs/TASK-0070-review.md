# TASK-0070 — Codex review of P1-32b (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a8b5-0400-76a1-bd07-90288ca511cb |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0070.prompt.md`, branch `feature/evidence-scan` @ `0716f73` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `e4dbc194d323eefa0c14529e617591bb8d9d6b63663d00f565447d0f0bf4f769` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — merge blocker: lost scan jobs can leave uploads permanently quarantined**

   **File:** `modules/Evidence/src/Actions/EvidenceUploadService.php:117–123`

   The dispatch exception is swallowed after the upload transaction commits. The comment says the queue’s retries will recover it, but queue retries only exist after a job has successfully been dispatched. If `dispatch()` fails because Redis/Horizon is unavailable, the asset remains `quarantined`/`pending` indefinitely and no scan job exists.

   **Scenario:** The database transaction commits, then Redis is unavailable during `ScanEvidenceJob::dispatch()`. The method reports the exception and returns the asset. Unless an independent reconciler exists, the evidence is never scanned or promoted.

   **Safe fix:** Add a transactional/outbox-based scan request or a scheduled reconciler that dispatches all `quarantined` assets with `pending`/`error` scan status. Do not rely on queue retry semantics after dispatch itself fails. Add a test for a dispatch failure followed by reconciliation.

2. **High — merge blocker: concurrent scans can create duplicate locked object versions**

   **File:** `modules/Evidence/src/Actions/EvidenceScanService.php:144–182`

   `claim()` does not transition the asset to an in-progress state. Two workers can both claim the same pending asset, scan it, and call `store()`. The pre-insert hash lookup is not locked, and both workers write the content-addressed object before the unique database insert resolves the race.

   **Scenario:** A duplicate job and a normal job run concurrently. Both write `sha256/...` to a versioned/object-locked S3 bucket. One blob row wins, but the other object version is unreferenced and may remain retained. This violates the “stored once”/single-object guarantee and complicates cleanup.

   **Safe fix:** Add an explicit `scanning` state or an atomic claim/lease under row lock, and ensure only the claimant scans/stores. Alternatively coordinate blob creation with a database lock/advisory lock before the object write. Add a real concurrent test with two workers/jobs.

3. **Medium — not necessarily a merge blocker: object-lock configuration is not verified for the S3 adapter**

   **File:** `modules/Platform/src/Storage/LockedObjects.php:25–34`

   The SDK `upload()` call passes an empty ACL string as the fourth positional argument. Depending on the AWS SDK version, this can result in an empty `x-amz-acl` value rather than omitting ACL configuration, potentially causing an S3-compatible store to reject the request. The code also accepts a missing `VersionId` without verifying that versioning/object lock was actually applied.

   **Scenario:** Production uses the configured S3-compatible adapter and rejects the upload because the ACL parameter is invalid, or accepts the upload without returning a version ID. The database then records a blob with no object version, undermining later retention/legal-hold operations.

   **Safe fix:** Call `upload()` with the SDK’s documented no-ACL form and explicitly verify the returned version ID when object locking is required. Fail closed if the store does not return a version ID. Add an integration test against the supported SeaweedFS configuration covering ACL omission, governance retention, and returned version ID.

4. **Medium — not necessarily a merge blocker: blob rows can be created without an assured matching locked object**

   **File:** `modules/Evidence/src/Actions/EvidenceScanService.php:157–180`

   The object is written before the `evidence_blobs` row, and the row insert is not part of the same transaction as the eventual asset decision. If the database insert fails after the object write, or if the process dies between those operations, an unreferenced object remains. Conversely, the fallback disk implementation writes without retention and returns `null`, although the application can use that path outside tests.

   **Scenario:** A database outage occurs immediately after a successful object upload. The retry writes again or leaves an orphaned locked object. On a non-S3 production-like disk, clean bytes can be stored without object retention.

   **Safe fix:** Make production configuration reject non-S3/object-lock-capable disks for evidence. Add an explicit orphan-reconciliation path and failure-injection tests. Persist/coordinate the blob creation and asset reference transactionally at the database level, while ensuring object-storage failures cannot produce an available asset.

5. **Low — not a merge blocker: scan error metadata is not monotonic with respect to time**

   **File:** `modules/Evidence/src/Actions/EvidenceScanService.php:205–222`

   `markError()` sets `scanned_at` even though no scan verdict was obtained. The field is documented as a scan result timestamp and may be interpreted as evidence that scanning completed.

   **Scenario:** ClamAV times out; the asset remains quarantined with `scan_status = error`, but `scanned_at` is populated. Downstream reporting treats it as scanned.

   **Safe fix:** Keep `scanned_at` null for retryable scanner errors, or add a separate `last_scan_attempted_at` field and document the distinction. Add an assertion to the scanner-error test.

## Missing tests

- Dispatch failure followed by a reconciler/outbox retry that eventually scans the committed asset.
- Two concurrent `ScanEvidenceJob` executions for the same asset, asserting one scan/store operation and one object/blob.
- Two concurrent assets with identical bytes, asserting exactly one stored object/version and one blob row.
- S3 integration test verifying:
  - no ACL header is sent;
  - governance retention is applied on the initial write;
  - a version ID is returned;
  - missing version IDs fail closed.
- Failure after object upload but before blob-row insertion, followed by retry/reconciliation.
- Production configuration test rejecting a non-object-lock evidence disk.
- ClamAV partial writes, truncated replies, oversized replies, connection closure during upload, and read timeout after partial response.
- Exact `stream: <signature> FOUND` parsing edge cases, including malformed/extra suffixes.
- Database constraint tests for invalid `evidence_blob_id` transitions in both directions and foreign-key deletion protection.
- Scanner error test asserting `scanned_at` semantics.

## Recommendation

**No.** The branch has a dispatch-loss path that can permanently strand committed uploads, and concurrent scans can create duplicate locked object versions. Both break stated P1-32b guarantees and should be fixed before merge.

## What I did not check

- I did not run tests, migrations, static analysis, queue workers, ClamAV, or object-store commands.
- I did not open `.env`, other ignored environment files, or `.secrets/`.
- The requested `docs/adr/ADR-0003.md` and `docs/adr/ADR-0007.md` paths were not present at those exact locations, so I could not inspect those files directly.
- Repository search tooling `rg` was unavailable in the shell; review proceeded using PowerShell and read-only Git commands.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "no", for #1 and #2. Each finding checked against the code; no credential file was opened (Codex's log names them only inside the docs it read).

| # | Finding | Disposition |
|---|---|---|
| 1 | High: a failed dispatch strands a committed upload | **Accepted.** The code comment claiming the queue's retries would recover it was wrong: no job exists to retry. New `evidence:dispatch-scans` (`modules/Evidence/src/Console/DispatchPendingScans.php`), scheduled every five minutes, queues the scan again for assets `quarantined`/`pending` for `evidence.scan_stale_minutes` (15): a lost dispatch or a worker that died mid-scan. `ScanEvidenceJob` is unique per asset, so a scan still queued or running is not doubled; `error` assets (retries exhausted) stay for the operator. Tests: `EvidenceUploadTest::test_a_failed_scan_dispatch_does_not_undo_an_already_committed_upload` now continues to the recovery (not queued before it is stale, queued after); `EvidenceScanTest::test_scans_left_waiting_are_queued_again_on_a_schedule` (stale pending only; fresh, `error` and decided assets are not; schedule `*/5`). |
| 2 | High: concurrent scans can write duplicate locked versions | **Accepted, narrowed.** The same asset cannot be scanned twice at once (`ShouldBeUnique`), but two assets with identical bytes could both miss the row and both write. `EvidenceScanService::store()` now holds a MySQL named lock per hash (`GET_LOCK`, `evidence.blob_lock_seconds` = 120) around the lookup, the object write and the insert; a scan that cannot get it fails and retries with nothing written. Test: `test_a_scan_waits_for_another_storing_the_same_bytes` (a second session holds the lock; the scan fails, no object or row; after release, stored once). |
| 3 | Medium: empty ACL; missing version ID | **ACL: declined.** The empty ACL is deliberate: SeaweedFS refuses the app key's writes otherwise (`PrivateBucketVisibility`, P1-32a), and `test_locked_objects_writes_once_with_retention_and_no_acl` asserts no `x-amz-acl` header is sent. **Version ID: accepted.** ADR-0007 needs `object_version_id` for retention and hold changes (P1-35), so an S3 write that returns none now fails closed (`test_locked_objects_fail_closed_without_a_version`). A probe write to the local SeaweedFS `evidence` bucket (synthetic, 1-day retention) confirmed it returns a version ID. |
| 4 | Medium: non-S3 fallback; orphan objects | **Fallback: accepted.** `LockedObjects` refuses a non-S3 disk outside the `testing` environment (`test_locked_objects_refuse_a_plain_disk_outside_tests`). **Orphans: deferred to P1-38** (its scope: race and failure-injection tests, the sweep). An object written before a failed insert is content-addressed: the retry writes the same key and inserts the row. |
| 5 | Low: `scanned_at` set on error | **Declined.** P1-32a's `evidence_assets_state_check` requires `scanned_at` whenever the scan is not `pending` (`(scan_status = 'pending') = (scanned_at IS NULL)`): for `error` it is the time of the last attempt, and `status` (still `quarantined`) is what says the asset was not cleared. |

Missing tests not added here: ClamAV partial or oversized replies and mid-stream disconnects (`ClamAvClientTest` covers error, unrecognised, unreachable and silent servers; any other reply is already an error by construction); the remaining race and failure-injection tests are P1-38's.
