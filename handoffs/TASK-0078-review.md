# TASK-0078 — Codex verification of the TASK-0077 dispositions (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aae4-aa82-72e0-be56-a62fe62d1195 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0078.prompt.md`, branch `feature/evidence-source-policy` @ `84b5ab8` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `2c0d9c3a80de979acd41fac04e51b246b33f1517c933cc2b7efd8176fce692da` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0077 findings

1. **Rollback index name — resolved**

   The new rollback test in [EvidenceSourcePolicyLockTest.php](D:/xampp_lite_8_3/www/competitive-ais/modules/Evidence/tests/Feature/EvidenceSourcePolicyLockTest.php:81) verifies that the migration can roll back and reapply when no evidence rows reference a source. The expected composite foreign-key/index name matches the migration’s generated name.

   The test is isolated by `DatabaseMigrations`; its explicit rollback is followed by reapplication, so the normal teardown should still see the migration as applied.

2. **Post-insert provenance immutability — acceptably deferred**

   No implementation was added, but the prior review explicitly accepted deferral to P1-52, and TASK-0078 confirms it remains in that milestone’s acceptance criteria. This remains an integrity gap until P1-52, but it is not a new regression in this branch.

3. **Real lock contention — resolved, with a test-quality caveat below**

   `EvidenceUploadService` calls `forCapture(..., true)` inside the transaction ([EvidenceUploadService.php](D:/xampp_lite_8_3/www/competitive-ais/modules/Evidence/src/Actions/EvidenceUploadService.php:148)), and `EloquentSourceLookup` applies `sharedLock()` when requested ([EloquentSourceLookup.php](D:/xampp_lite_8_3/www/competitive-ais/modules/Sources/src/Lookup/EloquentSourceLookup.php:27-30). This correctly makes the capture wait for the suspension transaction and then reread the source status.

   The separate-process helper provides real independent database connections and bounded cleanup. The disposition is technically addressed.

## 2. New finding

### Medium — race test can pass without proving the intended race

**File:** [EvidenceSourcePolicyLockTest.php](D:/xampp_lite_8_3/www/competitive-ais/modules/Evidence/tests/Feature/EvidenceSourcePolicyLockTest.php:42-65)

The test relies on fixed sleeps: the suspension holds the row for three seconds, while capture sleeps for one second. Under heavy scheduling or startup contention, the capture process may not reach its first lookup until after the suspension commits. It would then correctly reject the already-suspended source, causing the test to pass without verifying that:

1. the initial non-locking check saw `approved`; and  
2. the in-transaction shared-lock reread observed the later suspension.

**Scenario:** The capture child is delayed for more than two seconds after the common start. The suspension commits first; capture’s first lookup sees `suspended` and throws before entering the transaction. The assertions still pass.

**Safe fix:** Add explicit inter-process synchronization or an observable test seam. For example, have the capture task signal after its initial pre-transaction source check has completed, and have the suspension task commit only after that signal; then assert that the capture reached the transactional lookup before the suspension committed. Alternatively, use a dedicated injectable lookup/test hook that records completion of the initial check and assert the ordering directly. Avoid relying solely on sleep durations.

## 3. Recommendation

**Yes after fixes.**

The production locking change and rollback behavior are addressed, and the immutability issue is consistently deferred to P1-52. The race test should be strengthened before merge because its current timing can produce a false-positive result.

## Missing tests

- A deterministic ordering test proving the capture’s initial check completed while the source was still approved.
- A test proving the capture enters the transaction before the suspension commits, then blocks on the shared lock and rejects afterward.
- P1-52 tests for direct SQL/database-level mutation of `source_id` and `source_policy_id`.

## 4. What I did not check

- I did not execute PHPUnit, static analysis, frontend checks, migrations, or application commands.
- I did not open `.env`, other ignored environment files, or `.secrets/`.
- I did not perform a live MySQL concurrency run.
- I did not review unrelated modules or the entire historical branch beyond the requested diff and files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

TASK-0077 #1 and #3 resolved, #2 acceptably deferred to P1-52. Recommendation "yes after fixes". No credential file was opened (Codex's log).

**New medium (the race test relied on sleeps): fixed.** The order is now enforced, not timed:
1. The suspension takes the row lock and updates the status, uncommitted, then writes a signal file.
2. The capture starts only when that file exists, so its first check sees `approved`.
3. The suspension commits only once `SHOW FULL PROCESSLIST` lists the capture's share-locked read of `sources` waiting. Both sessions are the app account, so no extra privilege is needed.

Each wait fails after 30 s, and the run after 60 s.

Results:
- **With the lock:** 3 of 3 runs pass.
- **With `sharedLock()` removed from `forCapture`:** the test fails with `[{"outcome":"RuntimeException","message":"The capture never waited on the source row."},{"outcome":"ok"}]`. The capture records the asset after the suspension gives up.

So a pass now proves both orderings Codex asked for:
- the first check completed while the source was approved;
- the capture was blocked on the share lock when the suspension committed.

Verification: TASK-0079.
