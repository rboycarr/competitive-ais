# TASK-0071 — Codex verification of the TASK-0070 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a8dc-38e3-72f3-967f-b2168558abe8 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0071.prompt.md`, branch `feature/evidence-scan` @ `f3ac7de` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `88c9ebe36095b2b9dc484f22d7dce1f1df8ae123c52cdf3de37f77afbbab7dde` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0070 findings

1. **Lost dispatch — resolved.**  
   `DispatchPendingScans` finds stale quarantined/pending assets, and the provider schedules it every five minutes. The upload test covers a failed dispatch followed by scheduled recovery.

2. **Duplicate locked versions — resolved.**  
   `EvidenceScanService::store()` now uses a per-hash MySQL named lock around lookup, object write, and insert. The lock is released in `finally`; failure to acquire it throws before writing.

3. **Version ID — resolved. ACL decline correctly documented.**  
   `LockedObjects::write()` now fails closed when S3 returns no `VersionId`. The empty ACL is intentional for the SeaweedFS configuration and is covered by the existing test.

4. **Non-S3 fallback — resolved. Orphan handling correctly deferred.**  
   Non-S3 disks are refused outside `testing`. Object-orphan reconciliation remains explicitly deferred to P1-38, consistent with the prior disposition.

5. **`scanned_at` on error — correctly declined.**  
   The database state check requires non-pending scans, including `error`, to have `scanned_at`. The implementation treats it as the last scan attempt, while the quarantined status indicates the asset was not cleared.

## 2. New findings

### Medium — non-blocker: stale recovery can be delayed by the unique-job lock

**File:** `modules/Evidence/src/Jobs/ScanEvidenceJob.php:22–26`; `modules/Evidence/src/Console/DispatchPendingScans.php:24–32`

A worker that dies after the unique lock is acquired can leave the asset pending while the unique lock remains for up to 3600 seconds. The reconciler identifies the asset after 15 minutes, but dispatching the replacement job is suppressed by the still-held unique lock.

**Scenario:** A worker reserves a scan, dies before completing it, and the queue backend does not release the unique lock. The asset remains pending for up to one hour despite the five-minute scheduled reconciler.

**Safe fix:** Align `uniqueFor` with the job’s actual recovery policy, or explicitly release/expire the unique lock when a job is abandoned. Add a test covering a stale pending asset whose unique lock is still present.

This is not a merge blocker because the scan is eventually recoverable and the branch materially improves the prior permanent-loss behavior.

### Low — non-blocker: reconciliation can stop partway through a batch

**File:** `modules/Evidence/src/Console/DispatchPendingScans.php:29–34`

A dispatch exception is not handled per asset. If dispatch fails for one asset, the command aborts and later stale assets are not attempted during that run.

**Scenario:** Redis becomes unavailable while processing asset 501 of a batch. Assets 502 onward are skipped until the next scheduler invocation.

**Safe fix:** Catch and log dispatch failures per asset, continue processing the batch, and return a failure status or metric if any dispatch failed. Add a partial-dispatch test.

## 3. Recommendation

**Yes after fixes.**

The TASK-0070 blockers are addressed, and I found no new issue that makes the branch less safe than `main`. The two findings above are operational hardening items, especially the unique-lock recovery delay.

## 4. What I did not check

- I did not run tests, static analysis, migrations, queue workers, or object-store commands.
- I did not inspect `.env`, ignored environment files, or `.secrets/`.
- I did not verify runtime behavior against MySQL, Redis, SeaweedFS, or Laravel’s actual unique-job lock implementation.
- I did not review unrelated files outside the `0716f73..HEAD` change set.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

TASK-0070 findings resolved or correctly declined; recommendation "yes after fixes", for two non-blockers, both fixed:

- **Medium (unique lock delays recovery): fixed.** `ScanEvidenceJob::$uniqueFor` 3600 → 1800 s: still above the longest life of a job (3 × 300 s + 750 s backoff = 1650 s), so a living scan is never doubled, but a dead worker's lock lapses within 30 minutes. `test_scan_evidence_job_is_configured_for_retries_and_uniqueness` asserts both bounds. A test holding a real unique lock past expiry would test Laravel's cache lock, not this code; duplicates stay harmless (claim/decide guards, the blob lock).
- **Low (one failed dispatch stops the sweep): fixed.** `DispatchPendingScans` catches and reports each failed dispatch, continues, and exits 1 if any failed (`test_one_failed_dispatch_does_not_stop_the_sweep`).

No credential file was opened (Codex's log names them only inside the docs it read).
