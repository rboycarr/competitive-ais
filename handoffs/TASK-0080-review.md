# TASK-0080 — Codex verification of the TASK-0079 fix (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aaf5-09a4-7ba0-9030-7e3d716becd1 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0080.prompt.md`, branch `feature/evidence-source-policy` @ `699ed73` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `26c5ae5989f00afe1a1b7aedbafa364e0b0aef0b6beda59edf4c111d738ff909` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0079 finding

Resolved.

The suspension now reads the capture’s published MySQL `CONNECTION_ID()` and only commits after `SHOW FULL PROCESSLIST` contains that exact thread running the share-locked `sources` query (`EvidenceSourcePolicyLockTest.php:76–80`). This prevents an unrelated session from satisfying the synchronization condition.

The recorded verification also reports that removing `sharedLock()` causes the test to fail, which is the expected regression signal. I did not execute or independently verify those runs.

## 2. New findings

### Low — capture connection ID file can be read before the write is complete

File: `modules/Evidence/tests/Feature/EvidenceSourcePolicyLockTest.php:85`

`file_put_contents()` writes directly to the coordination file, while the suspension checks `filesize($capture) > 0` and immediately reads it. A partial write or filesystem timing anomaly could produce an incomplete or zero connection ID, causing the suspension to wait until timeout even though the capture is functioning.

Failure scenario: the suspension observes a partially written capture file, reads an invalid ID, never finds that ID in `SHOW FULL PROCESSLIST`, and the test fails after the polling timeout.

Suggested fix: write the ID to a uniquely named temporary file and atomically publish it with `rename()`, or use a lock/complete marker and validate the parsed ID before polling.

### Low — coordination files are not cleaned up on `Concurrently::run()` failure

File: `modules/Evidence/tests/Feature/EvidenceSourcePolicyLockTest.php:88–90`

The `unlink()` calls occur only after `Concurrently::run()` returns. If the runner throws before returning, the files remain in the system temporary directory.

Failure scenario: a fork/setup error or unexpected runner exception bypasses cleanup and leaves coordination files behind. Repeated test execution accumulates artifacts.

Suggested fix: wrap the concurrent run and assertions in `try/finally`, deleting both coordination files in the `finally` block.

## 3. Recommendation

Yes after fixes.

The TASK-0079 finding itself is resolved, but the two small test-harness robustness issues should be fixed before merging.

## 4. What I did not check

- I did not execute PHPUnit, Pint, Larastan, migrations, or application commands.
- I did not perform a live MySQL concurrency run.
- I did not inspect `.env` files, ignored credential files, or `.secrets/`.
- I did not independently verify that a reconnect inside `EvidenceUploadService` cannot occur between the published `CONNECTION_ID()` and the transactional lookup; the current code path appears to reuse the default connection, but this remains an environmental/runtime assumption.
- I did not review unrelated modules or the full branch history beyond the requested diff and files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

The TASK-0079 finding is resolved. Two new low test-harness findings, both fixed. No credential file was opened (Codex's log).

1. **Partial read of the connection-ID file: fixed.**
   - The capture writes its `CONNECTION_ID()` to `<file>.tmp` and `rename()`s it into place, which is atomic on one filesystem. The suspension never sees a partial file.
   - The suspension refuses an ID below 1 with a clear `RuntimeException`.
2. **Cleanup on runner failure: fixed.** `Concurrently::run()` sits in `try/finally`, and the `finally` block removes the signal, ID and temporary files.

Results: with the lock, 3 of 3 runs pass. With `sharedLock()` removed, the test still fails with "The capture never waited on the source row." Pint and Larastan pass.

On Codex's note about a reconnect between publishing the ID and the lookup:
- Both run on the default connection in the same child process.
- Laravel reconnects only after a lost connection. A reconnect would make the suspension time out and fail the test, never pass it falsely.

Verification: TASK-0081.
