# TASK-0055 — Codex verification of the TASK-0054 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (14:17:53–14:19:09 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19c9f-b63c-7962-84b1-cce583403356 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0055.prompt.md`, branch `feature/pennant-feature-flags` @ `0a2d78e` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `9b92f455002684c4a10e0f93f8db9c85c7e2f066f1a81744f985c36716bf0141` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0054 findings

- **High — Pennant writes in the migrator service bypass the audit: Confirmed fixed for the stated runtime behavior.**  
  `Features::enabled()` requires both a stored JSON `true` and the latest matching `feature.switched` audit context with `enabled: true` (`modules/Platform/src/Support/Features.php:52–80`). An unaudited stored `true` therefore evaluates as off. `switch()` also repairs mismatched state and records an audit row (`:104–113`).  
  Caveat: the migrator account can still directly mutate both tables; this is detection/ fail-closed behavior, not prevention.

- **Medium — No database-backed Sources flag test: Confirmed fixed.**  
  `SourceCollectionFlagTest` uses `DatabaseMigrations`, the real `features` table, the audited command, and verifies that an unaudited stored `true` is refused (`modules/Sources/tests/Feature/SourceCollectionFlagTest.php:18–55`).

- **Low — Concurrency test cache: Confirmed fixed.**  
  The test flushes Pennant’s cache and independently asserts the stored JSON value (`modules/Platform/tests/Feature/FeaturesTest.php:179–185`).

## 2. New findings

### Medium — Audit lookup is not covered by the claimed efficient index

File: `modules/Platform/src/Support/Features.php:74–80`  
Related schema: `modules/Platform/database/migrations/2026_11_01_100000_create_audit_tables.php:28–32`

The query filters by `action` and JSON `context->flag`, then orders by `id DESC`. The available `(action, occurred_at)` index does not cover the JSON flag predicate or ordering by `id`. As `audit_events` grows, every feature check may scan/filter many `feature.switched` rows and perform a filesort.

Scenario: a normal request checks the collection flag while the audit table contains millions of events. The query repeatedly scans the feature-switch subset, increasing latency and database load.

Safe fix: add a MySQL-compatible generated/indexed column for the flag name, or a dedicated indexed column for feature-switch events, and use an index supporting `(action, flag, id)`; verify the migration and query plan with `EXPLAIN` in a safe test database.

### Low — Forged audit rows can make a separately forced-on flag appear audited

Files: `modules/Platform/src/Support/Features.php:74–80`; `modules/Platform/src/Audit/AuditLogger.php:57–91`

The app account is permitted to insert into `audit_events`, and `lastAudited()` trusts the latest matching JSON context without verifying the audit row hash. An app-account attacker cannot enable a false flag by inserting an audit row alone, because `enabled()` also requires the stored feature value to be `true`. However, if the migrator-side direct mutation path first changes the stored value to `true`, an app-account attacker can insert a forged `feature.switched` row and cause the flag to evaluate as enabled.

Safe fix: do not use ordinary app-writable audit rows as an authorization input. At minimum, verify the row hash and require a trusted writer/actor marker for feature-switch events; preferably isolate feature-switch audit records in a table or write path unavailable to the app account.

## 3. Recommendation

**Yes after fixes.**

The three TASK-0054 findings are addressed, and the branch fails closed for the principal unaudited-on scenario. I recommend fixing the audit lookup/index design and closing the possibility of app-forged feature-switch audit records before merging.

## 4. What I did not check

- I did not run tests, migrations, static analysis, `EXPLAIN`, or Laravel commands, per the read-only review constraints.
- I did not inspect live MySQL grants or transaction visibility across separate runtime connections.
- I did not inspect the installed Laravel Pennant implementation.
- I did not open ignored credential files, `.secrets/`, or prohibited environment files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

The TASK-0054 findings confirmed fixed; Codex did not open any credential file. Recommendation "yes after fixes"; fixed in the next commit.

| Finding | Disposition |
|---|---|
| Medium: the audit lookup does not follow the index | **Fixed.** The lookup orders by `occurred_at DESC, id DESC`. InnoDB secondary indexes carry the primary key, so `(action, occurred_at)` is in effect `(action, occurred_at, id)`: MySQL walks the `feature.switched` entries newest first and stops at the flag's latest switch, without sorting. No schema change. Test `test_the_audit_lookup_follows_the_index_without_sorting` captures the query `enabled()` sends and asserts its `EXPLAIN` uses `audit_events_action_occurred_at_index` with no filesort. The lookup runs only while a flag is stored on. |
| Low: forged audit rows with a forced value | **Accepted, documented in docs/02.** It needs both the migrator account (to store `true`) and the app account (to add the audit row). The migrator account owns the schema and can already rewrite any table, the audit triggers included, so no check made with app-held keys would bind it; it stays an operator-only credential, absent from runtime containers. With the app account alone, no flag can be switched on (the stored value stays `false`). |

Also fixed in the same commit: the full suite on `0a2d78e` failed `MigratorIsolationTest` (TASK-0003 #6: only the Platform migration wiring may name the migrator connection), because `Features` named it. `feature:switch` now joins `PlatformServiceProvider::MIGRATOR_COMMANDS`, which run on the migrator account and refuse with instructions where it is absent; `Features::switch()` uses the default connection, so called anywhere else it runs as the app account and the database refuses it (tested).
