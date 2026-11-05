# TASK-0082 — Codex review of P1-38 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1ab89-4cce-75d3-92e5-55e185d69133 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0082.prompt.md`, branch `feature/evidence-dedup-retries` @ `58534a5` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `ddf761027fd6d34303beff3bd8bdc793926a8caa04feb28d039ecbd3390abe86` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

- **High — merge blocker — `modules/Evidence/src/Console/SweepQuarantine.php:42-57`**

  The sweep checks for waiting assets, then deletes orphan objects without locking or reserving the upload. An upload can write its quarantine object, pause for more than 60 minutes while its database transaction waits, and then commit an asset after the sweep has observed no matching asset.

  **Scenario:** upload writes `quarantine/<ULID>`; the asset transaction is blocked for 61+ minutes; hourly sweep sees no committed asset and deletes the object; the upload transaction commits, leaving a quarantined asset pointing to a missing object.

  **Safe fix:** establish an atomic ownership protocol. For example, insert the quarantined asset before or concurrently with the object write, or have the sweep lock/check an upload reservation row and re-check asset ownership immediately before deletion. Add a concurrency test with an intentionally delayed upload transaction.

## P1-38 acceptance

Not fully met.

Delivered:

- Per-asset retention derived from source-policy retention or the default, counted from upload time.
- Deduplication/retry race coverage and monotonic scan handling.
- Blob retention extension without shortening.
- Reconciliation of database/object hold and retention state.
- `deletable()` safeguards that inspect references and object-store lock state.
- Quarantine sweep and scheduling.

Undelivered or unsafe:

- The quarantine sweep does not safely exclude an upload whose asset transaction is still in progress. This violates the stated guarantee that it cannot delete an object still needed by an upload.
- The acceptance-required concurrency test for that upload/sweep race is missing.

## Recommendation

**No.**

The new sweep can delete an object that a still-committing upload will reference, making the branch less safe than `main`.

## Missing tests

- Delayed upload transaction versus quarantine sweep after the orphan-age threshold.
- Asset insertion racing with the sweep’s ownership query.
- Recovery behavior when an asset commits after its quarantine object was removed.
- Direct mutation attempts against `retention_until`, if “fixed at upload” is intended before P1-52.

## Unresolved assumptions / questions

- Should quarantine ownership be established before object upload, or should uploads acquire a durable reservation that the sweep honors?
- Is retention immutability intentionally deferred to P1-52, despite the P1-38 documentation describing it as never changed?

## What I did not check

- I did not execute tests, static analysis, migrations, builds, or application probes.
- I did not inspect `.env`, other excluded environment files, or `.secrets/`.
- I did not validate live MySQL, SeaweedFS, queue, or scheduler behavior.
- I reviewed the requested diff and relevant implementation/tests read-only; no files were modified.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

No credential file was opened (Codex's log).

| Finding | Disposition |
|---|---|
| High: the sweep can delete the object of an upload stalled between its write and its commit | **Fixed.** Each upload holds a MySQL named lock on its quarantine key (`EvidenceUploadService::uploadLockName`, `evidence-upload:<ULID>`). It takes the lock before the write and keeps it until its asset commits (`afterCommit`), or until its object is cleaned up after a failure. The sweep takes the same lock without waiting. It skips a key whose lock is held and counts it as "in use by an upload"; that is not an error. Under the lock it checks again for an asset, then deletes. Named locks belong to the connection, so a crashed upload releases its lock, and its uncommitted asset is rolled back with it. Tests: `EvidenceBlobRaceTest::test_a_sweep_during_a_stalled_upload_leaves_its_object` runs two processes: the upload is held inside its transaction while the sweep runs with every object counting as old. With the sweep's lock check removed, that test fails (the object was deleted). `EvidenceQuarantineSweepTest` adds a key held by another session (left, then removed once released) and checks that an upload holds its lock while it writes and releases it after the commit and after a failed write. |
| Missing test: recovery when an asset commits after its object was removed | Not reachable once the lock is in place. If an object ever went missing anyway, the scan fails at staging (size check), retries and ends as `evidence.scan_failed` for the operator. |
| Question: is `retention_until` immutability deferred to P1-52? | **Yes.** It is added to P1-52's acceptance with `source_id` and `source_policy_id`. docs/02 now says the application never changes it and that database-held immutability is P1-52. |
