# TASK-0048 — Codex verification of the TASK-0047 dispositions (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (06:27:53–06:28:29 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19af1-6950-7d92-a67c-4ec9523c5944 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0048.prompt.md`, branch `feature/p1-25-uniqueness-under-load` @ `e9f500d` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `a086e00af1d7c3136c0e5877285e76e896f795a57538c6080e74a489dd72caa3` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0047 dispositions

- **Timeout/hang fix — Confirmed.**  
  `tests/Support/Concurrently.php:96-104` polls with `WNOHANG`, applies a deadline, kills timed-out children, and reports `timeout` or `no result`. A sleeping task cannot make the parent wait indefinitely.

- **Non-vacuous tag/market assertions — Confirmed.**  
  `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:185-201` requires all tagging requests to succeed and verifies persisted links. Existing market assertions also cross-check successful outcomes against persisted rows.

- **Category-tree assertions — Confirmed.**  
  `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:139-158` verifies all four categories remain, rejects the two-way cycle, checks successful move targets, and verifies the descendant remains under `C`.

- **Fork-failure cleanup — Confirmed, with the new caveat below.**  
  `tests/Support/Concurrently.php:47-66` tracks children in `$unreaped`; a failed later fork leaves previously created children in that list, and `finally` kills and waits for them.

- **`CompetitorTagService` production fix — Confirmed.**  
  The reviewed diff does not alter this production code. The prior disposition remains supported by the stated base-commit review.

- **`ProductService` production fix — Confirmed.**  
  The reviewed diff does not alter this production code. The prior disposition remains supported by the stated base-commit review.

- **TASK-0047 PID-reuse finding disposition — Confirmed in substance.**  
  At `15282cd:tests/Support/Concurrently.php:60-63`, cleanup signalled only when `pcntl_waitpid(..., WNOHANG) === 0`; it did not signal when `waitpid` returned `-1`. The prior review’s concrete PID-reuse scenario therefore misread the condition. The new explicit tracking is safer because reaped PIDs are removed before cleanup.

- **New child-reaping test — Confirmed as present.**  
  `tests/Feature/ConcurrentlyTest.php:30-36` checks that no child remains after a timeout run.

- **Duplicate-tagging test — Confirmed as present and materially sound.**  
  `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:190-199` requires all four requests to succeed, exactly one link to exist, and exactly one `competitor.tagged` audit event for the tag.

## 2. New findings

### Medium — `waitpid()` errors are treated as successful reaping

**File:** `tests/Support/Concurrently.php:99-103`

**Problem:** `pcntl_waitpid($pid, $status, WNOHANG) !== 0` treats both a positive child PID and `-1` as “reaped.” A transient `waitpid()` error, such as interruption by a signal, removes the child from `$unreaped` even though it may still be running. `finally` then will not kill or reap it.

**Failure scenario:** A signal interrupts `pcntl_waitpid()` while a child is alive; PHP returns `-1`. The child is removed from `$unreaped`, `collect()` eventually returns, and the child remains running or becomes a later zombie. This violates the helper’s stated cleanup guarantee and the new test’s intended invariant.

**Safe fix:** Treat only a positive return value as reaped:

```php
$waited = pcntl_waitpid($pid, $status, WNOHANG);
if ($waited > 0) {
    unset($unreaped[$i]);
}
```

For `-1`, retain the PID and retry or raise a controlled error so `finally` can clean it up. The same distinction should be used wherever `waitpid()` results are interpreted.

## 3. Recommendation

**Yes after fixes.**

The branch fixes the TASK-0047 PID-tracking issue and adds the requested assertions. The `waitpid() === -1` handling is a remaining cleanup correctness issue, but it is localized and should be fixed before merging because it can leave a child process unreaped.

## 4. What I did not check

- I did not run tests, static analysis, migrations, or application commands.
- I did not inspect runtime behavior under actual signal interruption or forced `pcntl_fork()` failure.
- I did not re-review the unchanged production service implementations beyond the evidence recorded in `TASK-0047-review.md`.
- I did not inspect database lock traces, CI configuration, or production process settings.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Every TASK-0047 disposition confirmed, including that the PID-reuse finding misread the condition.

**New finding (medium: `waitpid()` returning `-1` counted as reaped): fixed.** A new `reaped()` helper counts a child as gone only when `waitpid()` returns its ID, or returns `-1` with `ECHILD` (no longer our child). Any other error, such as `EINTR` from an interrupted call, leaves the child in the unreaped list, so polling retries it. If it is still running at the deadline it is killed and reaped. `killAndReap()` repeats the blocking wait until the child is reaped. The harness and concurrency tests (9) pass with the change.
