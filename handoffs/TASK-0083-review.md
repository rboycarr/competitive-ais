# TASK-0083 — Codex verification of the TASK-0082 fix (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1ab92-53c3-7ee1-baaf-a2f9fb8cbc21 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0083.prompt.md`, branch `feature/evidence-dedup-retries` @ `4ac15b1` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `829c61aa731e2453102ebe518a31093a402883b8f6b9c137924b16462ae40830` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0082 finding

Resolved.

The upload acquires `GET_LOCK()` before writing its quarantine object and retains it until:

- the asset transaction commits via `afterCommit()`; or
- failure cleanup completes.

The sweep attempts the same named lock with a zero timeout and skips locked keys. For unlocked keys, it re-checks `evidence_assets` while holding the lock before deletion. This prevents an upload from committing an asset after the sweep has decided the object is orphaned.

The race test at `EvidenceBlobRaceTest.php:100-136` specifically coordinates a stalled transaction and sweep, and would fail if the sweep lock check were removed.

## 2. New findings

None identified in the reviewed implementation.

The lock name is bounded appropriately: `evidence-upload:` plus the final 26-character ULID at `EvidenceUploadService.php:194-198`. The lock is connection-scoped, and the cleanup/release paths are protected with `finally` blocks.

## 3. Recommendation

Yes — safe to merge into `main`, subject to CI validating the reported tests and static checks.

## Missing tests

No additional merge-blocking tests identified. Useful defensive coverage would include:

- connection loss during an upload transaction;
- an outer transaction rollback after the object is written;
- failure of the lock-release query;
- MySQL lock behavior under the production connection-pooling configuration.

## Unresolved assumptions / questions

- The deployment must use MySQL-compatible named-lock behavior consistently across upload and sweep connections.
- Operational handling should ensure abandoned orphan objects remain recoverable after a crashed process, relying on the sweep after the connection releases its lock.

## What I did not check

- I did not execute tests, static analysis, migrations, or application commands.
- I did not validate live MySQL, S3/SeaweedFS, queue, scheduler, or connection-pool behavior.
- I did not inspect excluded credential files or `.secrets/`.
- I did not perform a full repository-wide security review; review was limited to the requested diff and files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

The TASK-0082 finding was verified resolved, with no new findings and a recommendation of "yes". No credential file was opened (Codex's log).

Codex lists some defensive cases. None of them blocks the merge:
- **Connection loss or a crash:** the lock and the uncommitted asset go with the connection; the object is then an orphan for the sweep.
- **An outer transaction rolled back:** the lock is held until the connection closes, which is the safe side, because the sweep only skips the key.
- **Connection pooling:** the application uses no persistent connections or pooling.

No further tests are added.
