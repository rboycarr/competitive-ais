# TASK-0047 — Codex verification of the TASK-0046 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (06:01:22–06:02:44 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19ad9-2440-71e3-83d4-a2cf2e84f730 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0047.prompt.md`, branch `feature/p1-25-uniqueness-under-load` @ `15282cd` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `3497ebd85ed1a3e647add4269c5815671c194a0f33037d88f27617a7cf31537b` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0046 dispositions and production fixes

- **#1 timeout/hang fix — Confirmed.**  
  `tests/Support/Concurrently.php:75-99` uses `WNOHANG`, a deadline, `SIGKILL`, and reports `timeout`/`no result`. The parent no longer waits indefinitely.

- **#2 non-vacuous tag/market assertions — Confirmed.**  
  `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:210-229` matches successful tagging outcomes to persisted links; currency and market outcomes are also cross-checked at lines 237-248.

- **#3 category-tree assertions — Confirmed.**  
  `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:139-158` verifies all four categories remain, rejects two-way cycles, checks successful move targets, and verifies the descendant remains under `C`.

- **#4 fork-failure cleanup — Confirmed with a safety defect noted below.**  
  `tests/Support/Concurrently.php:48-64` places child cleanup in `finally`, so previously forked children are addressed if a later fork fails. However, the cleanup logic can signal a reused PID after a child has already been reaped.

- **`CompetitorTagService` production fix — Confirmed.**  
  `modules/Registry/src/Actions/CompetitorTagService.php:47-53` uses a plain existence read, while the primary key still arbitrates duplicate inserts and `UniqueConstraintViolationException` preserves idempotency. The tag row remains protected by `sharedLock()` at line 43, so removal cannot race the insert.

- **`ProductService` production fix — Confirmed.**  
  `modules/Registry/src/Actions/ProductService.php:129-133` first obtains the brand ID by plain read, then locks by primary key. The owner check at line 132 still requires matching `competitor_id`; a brand deleted between the two reads results in validation failure, not a product referencing a deleted brand. Once the shared lock succeeds, `BrandService::remove()` must wait.

## 2. New finding

### High — cleanup may kill an unrelated process after PID reuse

**File:** `tests/Support/Concurrently.php:60-63`

**Problem:** `collect()` reaps completed children and removes them from its local `$running` array. In `finally`, the original `$pids` array is iterated. For an already-reaped child, `pcntl_waitpid($pid, ..., WNOHANG)` returns `-1`, not `0`; the condition `!== 0` therefore executes `posix_kill($pid, SIGKILL)`.

**Failure scenario:** A child exits and is reaped by `collect()`. Before `finally` checks that PID, the operating system reuses the PID for another process. The harness sends `SIGKILL` to that unrelated process. This is especially relevant because the timeout test deliberately kills and reaps a child before cleanup.

**Safe fix:** Track the still-running PIDs returned by collection and only kill those. Alternatively, make `collect()` perform all kill/reap work and have `finally` clean files only; do not infer liveness from a second `waitpid()` call on already-reaped PIDs.

## 3. Recommendation

**No.**

The production fixes and strengthened assertions are sound, but the new harness cleanup path can kill an unrelated process under PID reuse. That makes the branch less safe than `main` and should be fixed before merging.

## 4. Missing tests and unchecked items

Missing or advisable tests:

- A harness test covering cleanup after a child was already reaped, with PID state tracked explicitly.
- A fork-failure cleanup test using dependency injection or a test seam around `pcntl_fork()`.
- Concurrent duplicate tagging of the same competitor, verifying exactly one audit event.

I did not run tests, static analysis, migrations, or database commands, as required. I did not inspect CI runtime behavior, actual MySQL lock traces, or production database settings.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Codex confirmed both production fixes and the four TASK-0046 dispositions.

**New finding (high, "cleanup may kill an unrelated process after PID reuse"): the failure scenario does not apply to the reviewed code, but the cleanup was rewritten so it no longer depends on that reasoning.**
- **Why the scenario does not apply.** At `15282cd` the `finally` block kills only when `pcntl_waitpid($pid, $status, WNOHANG) === 0`, which means "a child of this process that is still running"; the review read it as `!== 0`. For an already-reaped ID, `waitpid()` returns `-1`, so no signal is sent, even if the ID now belongs to another process. That process isn't our child, so `waitpid()` would return `-1` for it too. So the recommendation "No" ("less safe than main") rests on a misreading. The harness is test code only and runs in a throwaway container.
- **The rewrite.** `Concurrently::run()` now keeps an explicit list of children not yet reaped. `collect()` removes each child the moment it is reaped, and kills and reaps those still running at the deadline. `finally` signals only children that were never reaped (after a failed fork or collection) and never calls `waitpid()` on a reaped ID.
- **New test.** `ConcurrentlyTest` "reaps every child, so none is left to signal afterwards" asserts that after a run with a timed-out child, `pcntl_waitpid(-1, …, WNOHANG)` finds no child.

**Missing tests:**

| Suggested test | Status |
|---|---|
| Duplicate tagging of one competitor | **Added** to `test_tagging_different_competitors_with_one_tag_in_parallel_succeeds`: 4 parallel requests tag one competitor with one tag; all succeed, one link, exactly one `competitor.tagged` audit event. |
| Forced fork failure (a seam around `pcntl_fork()`) | Not added. It would mean adding a test-only injection point to the harness; the cleanup path is the same `finally` block that the reaping test covers. |
