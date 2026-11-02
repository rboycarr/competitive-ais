# TASK-0056 — Codex verification of the TASK-0055 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (14:58:46–14:59:32 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19cc5-2163-7381-a616-e313363f6508 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0056.prompt.md`, branch `feature/pennant-feature-flags` @ `62473b8` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `b6324175bd54b3bf652be0ddfe64083a215af1cb44de8ae8453ac5754a7d9ad5` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0055 findings

- **Medium — Audit lookup/index: Confirmed fixed.**  
  `modules/Platform/src/Support/Features.php:74–80` now orders by `occurred_at DESC, id DESC`, matching the `(action, occurred_at)` index. InnoDB secondary indexes include the primary key, so `id` provides the tie-breaker. The query-plan test captures the actual query and checks the selected index and absence of `filesort`.

  Caveat: the test is environment-sensitive on small tables because MySQL may legitimately choose a table scan or different plan based on statistics. This is addressed below as a new test-quality finding.

- **Low — Forged audit row with forced value: Accepted disposition remains reasonable.**  
  The scenario requires both migrator access to force the feature value and app access to insert a matching audit row. The migrator account already has authority to rewrite the relevant tables and is intended to be operator-only. The app account alone cannot enable a flag because it cannot update `features`.

- **TASK-0003 migrator isolation regression: Confirmed fixed.**  
  `Features::switch()` no longer names the `migrator` connection, and `MigratorIsolationTest` allows the connection name only in the provider/migration-wiring locations. `feature:switch` is included in `PlatformServiceProvider::MIGRATOR_COMMANDS`, so normal command execution switches the default connection to `migrator`.

## 2. New findings

### Medium — Query-plan test is not a reliable cross-environment guard

**File:** `modules/Platform/tests/Feature/FeaturesTest.php:217–239`

**Problem:** The test requires MySQL to select exactly `audit_events_action_occurred_at_index` and to omit `filesort` on a very small test table. MySQL may choose a table scan because it is cheaper for a small table, depending on statistics, optimizer version, or configuration. The test could fail despite the query being correctly ordered and indexed in production. Conversely, a passing test on the fixture does not prove the plan remains appropriate at production cardinality.

**Failure scenario:** CI uses a different MySQL 8.4 minor version or optimizer statistics. `EXPLAIN` returns `key = null`, causing a false failure. Alternatively, the test passes with three rows but production performs poorly after the audit table grows substantially.

**Safe fix:** Treat the index as a structural requirement separately from the optimizer choice:

- Assert the migration defines the expected index.
- Use `EXPLAIN` with a sufficiently representative fixture or optimizer settings suitable for the test.
- Assert that the plan does not contain `Using filesort` when the index is selected, but avoid requiring one exact `key` unless the test intentionally controls optimizer conditions.
- Add a production-scale performance/integration check using a larger synthetic audit dataset.

### Low — Default connection may remain switched after an exception in a long-lived process

**File:** `modules/Platform/src/PlatformServiceProvider.php:105–123`

**Problem:** The default connection is restored only from `CommandFinished`. If a command throws before `CommandFinished` is dispatched, the provider leaves the application’s default connection set to `migrator`.

The task brief acknowledges that the process normally ends after a command failure, but this is unsafe in long-lived Artisan/test processes and can affect subsequent commands executed in the same application instance.

**Failure scenario:** A test or worker invokes `feature:switch`, `Features::switch()` throws after the `CommandStarting` listener changes the default, and the same PHP process later handles another command or performs application work. Its database reads/writes unexpectedly use the migrator connection.

**Safe fix:** Wrap command execution with a restoration mechanism that runs on both success and exception paths, or register an exception/termination cleanup that restores the prior default connection. Also add a test that forces `feature:switch` to throw and verifies the default connection is restored.

### Low — `--database` bypass is not explicitly constrained for `feature:switch`

**File:** `modules/Platform/src/PlatformServiceProvider.php:105–114`

**Problem:** The migrator switching logic is skipped whenever `--database` is present. This is appropriate for schema commands that support an explicit database, but `feature:switch` is intended to run only through the migrator account. If Laravel or a future command signature makes `--database` available to `feature:switch`, the command could bypass the migrator enforcement and run against the app/default connection.

**Failure scenario:** An operator invokes `feature:switch --database=app`; the provider does not switch to `migrator`, and the command either fails unpredictably due to grants or is later made writable through an overly broad grant.

**Safe fix:** Handle `feature:switch` separately: always require the configured migrator connection and reject `--database` for that command, or explicitly permit only `--database=migrator`. Add tests for the option/bypass path.

## 3. Recommendation

**Yes after fixes.**

The original TASK-0055 findings are resolved or reasonably accepted. The branch should address the exception-path connection restoration before merge. The query-plan test should also be made less optimizer-dependent. The `--database` issue should be constrained before merge unless the command framework definitively guarantees that option cannot be supplied.

## 4. What I did not check

- I did not run PHPUnit/Pest, Laravel commands, migrations, static analysis, or `EXPLAIN`; the review was read-only and the instructions prohibited claiming execution.
- I did not inspect live MySQL grants or production table cardinalities/statistics.
- I did not inspect Laravel’s installed `CommandStarting`/`CommandFinished` dispatch behavior beyond the repository code.
- I did not open `.env`, other ignored environment files, or `.secrets/`.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

The TASK-0055 findings confirmed fixed or reasonably accepted; the migrator-isolation regression confirmed fixed. Recommendation "yes after fixes"; fixed in the next commit.

| Finding | Disposition |
|---|---|
| Medium: the query-plan test depends on the optimizer | **Fixed.** The test now asserts the index structurally (`information_schema.STATISTICS`: `audit_events_action_occurred_at_index` on `action`, `occurred_at`) and runs `EXPLAIN` after 300 unrelated audit rows and `ANALYZE TABLE`, so a scan is clearly the worse plan; it still requires that index and no filesort. A production-scale load check is not part of this branch: docs/11 has no performance story yet, so it is raised with the PO as a follow-up. |
| Low: the default connection stays on the migrator after an exception | **Fixed for feature:switch.** The command catches a failed switch, reports it (`report()`), prints "The [flag] feature was not switched: …" and returns exit code 1, so `CommandFinished` runs and the provider restores the connection. Test: with the audit failing, `Artisan::call('feature:switch', …)` returns 1, the default connection is the app account again, and the flag stays off. The schema commands keep Laravel's own behavior (an exception ends the process). |
| Low: `--database` skips the migrator switch | **Verified safe, now tested.** `feature:switch` defines no `--database` option: the provider skips the switch, then the console refuses the option ("The "--database" option does not exist.") before `handle()` runs, so nothing is written and the connection is unchanged. Test `test_switching_cannot_name_another_connection`. |
