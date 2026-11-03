# TASK-0062 — Codex verification of the TASK-0061 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-03 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19ed8-4458-70e0-8e47-3c7c3b2d8f64 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0062.prompt.md`, branch `feature/source-lifecycle` @ `b9b9bc2` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `3921f9e01ea255967ad74a791b7d25ca4fd08ea3e2f24678f7866442d3106fd1` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0061 disposition

- Finding 1 — resolved.  
  `source_status_changes.changed_at` is now `DATETIME(6)` at `modules/Sources/database/migrations/2026_11_17_000100_create_source_status_changes_table.php:30`. The new migration converts `source_policies.decided_at` to `DATETIME(6)`. `SchemaConventionsTest` also detects new or unexpectedly retained `TIMESTAMP` columns.

- Finding 2 — correctly deferred.  
  The database does not yet enforce transitions on `sources.status` itself. This is explicitly added to P1-52 acceptance criteria in `docs/11-delivery-roadmap.md`, including direct-update tests. The current application services enforce the lifecycle rules, and this omission does not make this branch less safe than its parent.

## 2. New finding

### Medium — merge blocker

**File:** `modules/Sources/database/migrations/2026_11_17_000200_use_datetime_for_source_policy_decisions.php:18-20`

**Problem:** The rollback converts `source_policies.decided_at` back to `TIMESTAMP(6)`, directly violating the project’s documented rule that timestamps must use `DATETIME(6)`. It can also fail or truncate data if any future `DATETIME` value lies outside MySQL’s `TIMESTAMP` range.

**Failure scenario:** After the migration has been applied, a policy decision is stored with a date beyond the `TIMESTAMP` range. A rollback attempts `MODIFY ... TIMESTAMP(6)`, causing migration failure or data loss. Even when successful, the rollback restores a schema explicitly forbidden by `docs/02-database-erd.md §1` and causes `SchemaConventionsTest` to fail.

**Safe fix:** Make the rollback refuse safely with a clear exception and documented recovery procedure, or add an explicit range check before conversion and document that rollback restores the legacy schema only under controlled conditions. The preferred approach is to treat this corrective migration as non-reversible and require backup/restore for reversal.

## 3. Missing tests

- Migration rollback with a `decided_at` value beyond the MySQL `TIMESTAMP` range.
- Verification that rollback cannot silently reintroduce forbidden `TIMESTAMP` columns.
- Successful forward migration preserving microsecond precision and UTC values.
- P1-52 direct SQL tests for every forbidden `sources.status` transition once that story is implemented.

## 4. Recommendation

**Yes after fixes.**

The prior findings are resolved or properly deferred. The rollback behavior should be corrected before merge because it can fail during rollback and violates the documented schema guarantee.

## 5. What I did not check

- I did not run tests, migrations, static analysis, or database commands.
- I did not inspect `.env`, credential files, or `.secrets/`.
- I did not verify the full-suite evidence reported in TASK-0062; it was treated as implementer-provided evidence.
- I did not review future P1-52 implementation because it is not present in this branch.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-03)

TASK-0061 #1 resolved and #2 correctly deferred; recommendation "yes after fixes"; fixed in the next commit. No credential file was opened (Codex's log).

| Finding | Disposition |
|---|---|
| Medium: the conversion migration's rollback restores TIMESTAMP(6) | **Fixed.** `down()` now keeps DATETIME(6), with the reason in the code: going back would reintroduce the forbidden type and could fail on values outside its range. It cannot refuse instead, since test teardown (DatabaseMigrations) rolls back every migration; rolling back further drops the table with P1-31a's migration, and running `up()` again is harmless. `test_rollback_is_refused_while_changes_exist` now asserts the column is `datetime(6)` before and after that rollback. |
| Missing tests | Added: rollback keeps DATETIME(6) (above). Not needed: a rollback with values beyond the TIMESTAMP range, since the rollback no longer converts. Not added: a forward-conversion test with existing rows, because it needs DDL inside the test, which commits the RefreshDatabase transaction; MySQL converts TIMESTAMP to DATETIME in the session time zone (`+00:00` on every connection) with the same precision (6), so values and microseconds are kept. P1-52 tests come with P1-52. |

The full suite on `b9b9bc2` had one failure outside this branch: `RegistryConcurrencyTest::test_parallel_category_moves_never_break_the_tree` raised a ValidationException in the test process (319 other tests passed). It passed when run alone and in every earlier full run; it is reported to the PO as an intermittent failure to investigate.
