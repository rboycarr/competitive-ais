# TASK-0008 — Codex verification of the TASK-0007 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (19:54:55–19:55:39 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19387-8a2a-7821-a019-40586c7603a4 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0008.prompt.md`, branch `fix/task-0003-hardening` @ `55c7e53` |
| Invoked by | Claude Code, under the Product Owner's 2026-11-01 approval of the pre-merge review ("approved. proceed.") |
| Raw output SHA-256 | `e6199eeb25a84dff0bafd23849b52430f799171575ac33965910a450e385c888` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. Accepted TASK-0007 findings

- **High findings 1–2 — Complete.**  
  `modules/Platform/src/Database/TableGrants.php:251–270` now uses the explicit configured database list, validates identifiers, requires every database to exist/be visible, and restricts `apply()` to that list at `:97–101`. `verify()` also reports grants outside the list at `:171–173`.

- **Medium finding 3 — Complete.**  
  `scripts/ci/lib.sh:41–42` adds `|| true` to both diagnostic pipelines, allowing logs without matching error lines or empty logs to produce output.

- **Medium finding 4 — Rejected disposition remains reasonable.**  
  The parser remains strict at `modules/Platform/src/Database/TableGrants.php:216–233`, but fails closed on unexpected output. I found no new regression that invalidates the documented rejection rationale.

## 2. New problems introduced

### High — CI migration round-trip can mutate the main database

**Files:** `compose.yaml:18–22`; `modules/Platform/src/Database/TableGrants.php:97–101`; existing caller `scripts/ci/backend.sh:58–62`

The new global `DB_GRANT_DATABASES` value contains both the main and test databases. The CI migration round-trip overrides only `DB_DATABASE` to the test database, but `DB_GRANT_DATABASES` remains the host-interpolated list containing the main database. `TableGrants::apply()` therefore scans and changes grants in both databases during a test-database-only migration run.

**Failure scenario:** A CI migration creates or drops tables, or changes grant policy, while running with `DB_DATABASE=<test>`. `apply()` still processes the main database, potentially revoking or granting privileges there. This contradicts `backend.sh:6`, which states the round-trip “never” uses the main database.

**Suggested fix:** Make the managed-database list explicit per invocation/environment, or ensure the CI test container sets `DB_GRANT_DATABASES` to the test database when `DB_DATABASE` is overridden. Add a regression test proving the migration round-trip cannot inspect or mutate the main database.

## 3. Missing tests

- Compose/config test where `DB_DATABASE` is overridden to the test database and `DB_GRANT_DATABASES` remains host-derived; assert only the test database is managed.
- CI migration-round-trip test with a sentinel grant/table in the main database; assert it remains unchanged.
- Configuration test for whitespace, duplicate database names, and an empty `DB_GRANT_DATABASES` value.
- Test that a configured database becoming inaccessible fails before any grant mutation occurs.

## 4. Recommendation

**No.**

The accepted findings are addressed, but the fix broadens grant application into CI’s main database despite the stated test-isolation guarantee. This makes the branch less safe than its parent until the managed-database configuration is scoped correctly for overridden test runs.

## 5. What I did not check

I did not run tests, PHPStan, Pint, Bash scripts, Docker, migrations, SQL, or CI workflows. I did not inspect runtime database state or execute network calls.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| High 1 | Test-database migration round-trip changes grants on the main database | High | **Accept** | Correct, and the Pest probes Claude Code added in TASK-0006/0007 also created tables in the main database. `apply()` now changes and verifies only the connection's own database. `database.grants.databases` only tells known databases (each checked by migrations against it) from unknown ones; a grant on an unknown database fails verification. The two Pest tests that touched the main database were removed. `accounts-test.sh` (which seeds the main database as root by design) now runs `db:apply-grants` on the test database with only that database managed, and checks it rejects the main database's grants **and leaves them unchanged**. New Pest test: `apply()` refuses a database outside the list. |
| Missing tests: empty/duplicate/whitespace list | — | — | **Accept in part** | Duplicates are removed and empty entries filtered in config; every name is validated as an identifier; an empty list fails because the connection's database must be listed (covered by the new refusal test). |
| Missing test: listed database inaccessible fails before mutation | — | — | **Accept (no change needed)** | `managed()` runs before any GRANT/REVOKE in `apply()`. |
