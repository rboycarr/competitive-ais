# TASK-0079 — Codex verification of the TASK-0078 fix (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aaea-9796-7ea1-81bb-b112ca78b07b |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0079.prompt.md`, branch `feature/evidence-source-policy` @ `751bbd2` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `14736ec673b00b6759ed7edae0d6e7c74199a943aa2492f6e943fcf7712aca11` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0078 finding

Resolved.

The prior timing-based race was replaced with enforced ordering:

- The suspension transaction locks and updates the source before signaling.
- Capture starts only after that signal, so its initial non-locking read occurs before suspension commits.
- The suspension waits until a source-row shared-lock query appears in `SHOW FULL PROCESSLIST`.
- The 60-second outer timeout and 30-second polling timeout prevent indefinite hangs.
- Temporary signal files are removed afterward.

This directly addresses the previous false-positive scenario. I did not execute the reported verification runs; I reviewed the code and the recorded results only.

## 2. New findings

### Medium — process-list match is not specific to the tested source or capture

File: `modules/Evidence/tests/Feature/EvidenceSourcePolicyLockTest.php:73-75`

The test treats any process-list query matching:

```regex
from `sources`.*(lock in share mode|for share)
```

as proof that this capture is waiting on this source row. It does not identify the source ID, connection/thread ID, or capture process.

Failure scenario: another concurrent test or application connection issues a shared-lock query against `sources`. The suspension sees that unrelated query, commits, and the capture may then observe `suspended` during its initial check. The test still reports the expected `ValidationException`, despite never proving that the intended in-transaction lookup waited on the lock.

Safe fix: make the synchronization specific to the capture connection and source row. For example, have the capture publish its MySQL connection/thread ID and source ID after entering the transaction, then require `SHOW FULL PROCESSLIST`/`INNODB_LOCK_WAITS` to match that connection and query. Alternatively, use a dedicated database synchronization/test hook that records the capture’s transactional lookup directly.

## 3. Recommendation

Yes after fixes.

The TASK-0078 finding is resolved, but the new process-list matching weakness should be addressed before merging if this test is intended as strong regression evidence.

## 4. What I did not check

- I did not execute PHPUnit, Pint, Larastan, migrations, or any application command.
- I did not perform a live MySQL concurrency run.
- I did not inspect credential files, `.env*`, or `.secrets/`.
- I did not review unrelated modules or the full historical implementation outside the requested diff.

<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

The TASK-0078 finding is resolved. Recommendation "yes after fixes". No credential file was opened (Codex's log).

**New medium (the process-list match was not specific to the capture): fixed.**
- After the suspension's signal, the capture task writes its MySQL `CONNECTION_ID()` to a second file, then starts the upload on that connection.
- The suspension commits only once `SHOW FULL PROCESSLIST` lists a thread with that exact `Id` running a share-locked read of `sources`. Every other session is ignored.
- The suspension holds only this source's row, so that thread's waiting read is the capture's in-transaction lookup of this source.

Results:
- **With the lock:** 3 of 3 runs pass.
- **With `sharedLock()` removed from `forCapture`:** the test still fails, with `[{"outcome":"RuntimeException","message":"The capture never waited on the source row."},{"outcome":"ok"}]`.

Pint and Larastan pass. Verification: TASK-0080.
