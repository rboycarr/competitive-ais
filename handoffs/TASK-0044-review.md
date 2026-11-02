# TASK-0044 — Codex review of P1-23c (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (04:09:45–04:10:41 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19a72-eea5-7c33-be1f-2346c2ff6ac4 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0044.prompt.md`, branch `feature/p1-23c-market-grants` @ `ff1f937` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `618a90f5dd7294a3b8ad5a6a862a81b03aa3504f26f6103c72cb3b9ef133cb39` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Low — Market picker omits the market code**

   **File:** `modules/Identity/resources/js/Pages/Identity/Admin/AccessGrants.vue:117`

   The requirement says market names must be displayed as `code · name`. Existing grants use the formatted label from the controller, but the new-grant picker renders only `market.name`. With markets sharing similar names, an admin may select the wrong market.

   **Scenario:** Two markets have names such as “National” but distinct codes. The picker displays both identically.

   **Fix:** Provide separate `code` and `name` fields in the UI option DTO, or ensure the controller’s `market` label is rendered as `code · name` and use that value in the picker. Add a frontend test asserting the option text includes both.

## Missing tests

- `GrantAccessScope::allows()` cases with:
  - competitor and market scopes where only one target is covered;
  - different grant classification ceilings for competitor versus market;
  - restricted records with only market scopes;
  - multiple markets where only one market is granted;
  - unscoped records with market grants, proving the grant does not widen access.
- Direct `applyTo()` versus `allows()` equivalence for mixed competitor/market records.
- Market lookup contract tests for case normalization, ordering, and summaries.
- Controller test proving market options and existing-grant labels expose only `code · name`.
- Migration test proving the market foreign key uses `RESTRICT` and that rollback performs no DDL when market grants exist.

## Recommendation

**Yes after fixes.**

The authorization implementation appears consistent with the documented all-of predicate, the migration is ordered after the markets table, and the rollback guard checks for market grants before DDL. The remaining issue is a low-severity UI requirement violation.

## What I did not check

- I did not run PHPUnit, Vitest, static analysis, migrations, or frontend builds.
- I did not inspect runtime database state or execute SQL.
- I did not verify the branch’s claimed test results independently.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

**Finding 1 (low, the market picker omits the market code): no change needed. The picker already shows the code.** `AccessGrantController::index()` builds each picker option's `name` with `marketLabel()`, which gives "code · name", the same label as the grants list. Two tests assert it:
- `AccessGrantTest::test_a_grant_can_open_one_market` expects the `markets` option `['ulid' => …, 'name' => 'PH · Synthetic PH']`;
- `AccessGrants.test.ts` expects the option texts `['Choose…', 'PH · Synthetic']`.

**Missing tests:**

| Suggested test | Status |
|---|---|
| Competitor and market grants at different levels | **Added:** `AccessScopeTest::test_a_record_about_a_competitor_and_a_market_opens_only_up_to_the_lower_of_both_grants`, with competitor A at confidential and market M at internal. Both `allows()` and `visibleTo()` are checked: an internal A+M record is visible, a confidential A+M record is hidden. |
| Only one of the competitor and the market covered | Already in the access matrix: grant `competitor:A` or `market:M` against record `competitor-and-market` (hidden), and `pair:A:M` (visible). |
| Restricted records with only a market | Already in the matrix: `market:N:restricted` against `restricted/market-n`. |
| Several markets, only one granted | Already in the matrix: `market:M` against `markets`. |
| Unscoped records with market grants | Already in the matrix: no widening. |
| `applyTo()` against `allows()` | Already in the matrix: both are checked against the same independent `expected()` over every case, with lists and HTTP reads too. |
| `MarketLookup` details | Ordering and labels are covered through the grants page. Case handling follows `CompetitorLookup` (`strtoupper` on the ULID). |
| Migration: RESTRICT and rollback without DDL | Rollback guard: `test_rolling_back_refuses_while_market_grants_exist`, which checks the grant afterwards. RESTRICT is declared in the migration, as for competitors. |

**Mutation check** (run by Claude Code, then reverted with `git checkout`): with the market condition removed from both `GrantSet::covers()` and `GrantAccessScope::applyTo()`, the matrix failed on every read path. `allows()` disagreed with the predicate in 236 cases, `visibleTo()` in 205, and HTTP reads in 114.
