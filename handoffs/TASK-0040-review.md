# TASK-0040 — Codex review of P1-23a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (00:58:27–00:59:21 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a199c3-ccb4-7433-b4a2-a2a5440f67e4 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0040.prompt.md`, branch `feature/p1-23a-markets-regions` @ `e8f144b` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `22842be90abd023477f1de30e83249853f2490415d8c6a239f46c4cdc90c3756` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — market codes are not database-enforced as immutable**

   **File:** `modules/Registry/database/migrations/2026_11_11_000200_create_markets_table.php:13-35`; `config/database.php:238-244`

   The `markets` table is classified `no_delete`, granting the app account `UPDATE` on every column. `MarketService` omits `code` from normal updates, but a direct SQL path or future application code can change it.

   **Failure scenario:** Any code running with the app database account executes:

   ```sql
   UPDATE markets SET code = 'NEW-CODE' WHERE id = 1;
   ```

   This succeeds, violating the documented “code is fixed” invariant and potentially invalidating market grants or downstream references.

   **Suggested fix:** Enforce immutability at the database layer, preferably with a `BEFORE UPDATE` trigger rejecting changes to `code` and `ulid`, or use column-level privileges so the app cannot update those columns. Add a direct-write regression test.

2. **Low — the read-only grant classification is not fully fail-closed in its diagnostics**

   **File:** `modules/Platform/src/Database/TableGrants.php:125-129`

   When an unclassified table is found, the exception text lists only `mutable`, `append_only`, `no_delete`, and `app_excluded`; it omits the newly supported `read_only` class. The enforcement itself still fails closed, but the remediation guidance is inaccurate.

   **Failure scenario:** A future migration creates an unclassified table. The grant application fails, but the error tells the maintainer to classify it without mentioning `read_only`, increasing the chance of assigning an incorrect write-capable class.

   **Suggested fix:** Include `read_only` in the classification error message and add an assertion covering the diagnostic text.

## Missing tests

- Direct app-account `UPDATE markets SET code = ...` must fail.
- Direct app-account `UPDATE markets SET ulid = ...` should fail if ULIDs are also intended to be immutable.
- Concurrent market updates must preserve the fixed code invariant.
- A direct app-account insert/update/delete attempt against `currencies` should be tested against the real table, not only the probe table.
- A migration/grant test should verify that a table classified only as `read_only` receives exactly `SELECT`, including after re-running grant application.
- A route test should verify that a nonexistent market returns 404 and does not expose registry data.

## Recommendation

**Yes after fixes.**

The branch correctly implements the `read_only` grant class, seeds currencies through the migrator migration path, applies `taxonomy.manage` checks at both routes and services, and uses composite database constraints to enforce region market ownership and strictly deepening levels.

The market-code immutability gap should be fixed before merge because “fixed code” is a stated data-integrity guarantee, while the current protection exists only in the service layer.

## What I did not check

- I did not execute the test suite, static analysis, frontend checks, migrations, or database-account probes, per the read-only review instructions.
- I did not inspect unrelated files outside the branch diff except the specifically requested project guidance and documentation.
- I did not verify the reported CI results in `TASK-0040.md`; they are treated as claims from the brief, not as tests run by me.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes".

| # | Finding | Decision | Rationale |
|---|---|---|---|
| 1 | Market code fixed only by the service (Medium) | **Documented precisely; scheduled as P1-52 for every fixed field** | Correct that a direct UPDATE by the app account can change the code. The impact is lower than stated: nothing references a market by its code (market grants, P1-23c, and prices use `market_id`), so no link breaks. The same holds for the other fields documented as fixed (competitor slug, product and brand owner, region level outside its subtree): all are kept by the services today. Enforcing them in the database needs one mechanism for all of them (triggers need extra migrator privileges and binary-log settings in the init script; column-level privileges need `TableGrants` support), so it becomes story P1-52 in Sprint 1.5 (docs/11), with a direct-UPDATE test per field. docs/02 now says the code is fixed by `MarketService`, not by the database. |
| 2 | Unclassified-table message omits `read_only` (Low) | **Fixed** | The message now lists `TableGrants::CLASSES`; `test_unclassified_table_gets_no_access_and_fails_the_grant_run` asserts the full list. |

| Missing test | Decision | Rationale |
|---|---|---|
| Direct UPDATE of the market code or ULID refused | **Defer** | P1-52 (see #1). |
| Concurrent market updates | **Defer** | P1-25 (parallel requests); updates lock the market row. |
| Writes against the real `currencies` table | **Covered / added** | `test_every_existing_read_only_table_rejects_writes` already ran on `currencies` (exactly SELECT; UPDATE, DELETE, TRUNCATE refused); it now also refuses an INSERT … SELECT on every read-only table. |
| `read_only` gets exactly SELECT, also after re-applying | **Covered** | The same test (privileges equal `['SELECT']`), `test_app_grants_on_every_table_match_the_policy` and `test_applying_grants_is_idempotent` run over all classes. |
| Unknown market is 404 | **Added** | `MarketTest::test_only_taxonomy_managers_reach_markets`. |
