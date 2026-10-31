# TASK-0007 — Codex verification of the TASK-0006 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (19:49:01–19:49:36 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19382-2541-7de3-9d02-905d7585bb09 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0007.prompt.md`, branch `fix/task-0003-hardening` @ `2a58cac` |
| Invoked by | Claude Code, under the Product Owner's 2026-11-01 approval of the pre-merge review ("approved. proceed.") |
| Raw output SHA-256 | `2165b18465ba525d1bea547beffa8b31bda256b86a68f19bac8e830506595ba6` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0006 findings 1–6

1. **Complete** — `modules/Platform/src/Database/TableGrants.php:155–190, 250–257`  
   `verify()` now compares grants across all schemas returned by `schemas()` and flags grants outside that set.

2. **Complete** — `scripts/ci/lib.sh:36–43`, `scripts/ci/backend.sh:22–29`, `scripts/ci/redact-test.sh:32–41`  
   Annotation construction was centralized in `annotation_body()`, used by both the backend trap and the test. Percent and newline escaping are tested.

3. **Complete** — `modules/Platform/src/Database/TableGrants.php:214–238`  
   `SHOW GRANTS` lines must match the configured user and host, including `GRANT USAGE`.

4. **Complete** — `modules/Platform/src/Database/TableGrants.php:220–225`  
   Temporary grant-check connections are purged in `finally`.

5. **Complete for the accepted stated scope** — `scripts/ci/lib.sh:6–14`, `scripts/ci/redact-test.sh:13–41`  
   Matching is case-insensitive for credential-like names, `.secrets` entries are included, and representative variants are tested.

6. **Complete** — `modules/Platform/tests/Feature/DatabaseAccountsTest.php:207–223`  
   The dropped-table test now checks stale grants for both app and retention accounts.

## 2. New problems

### High — `schemas()` does not implement the claimed definition of “managed”

**File:** `modules/Platform/src/Database/TableGrants.php:245–257`

`schemas()` returns every non-system schema visible through `information_schema.SCHEMATA`; it does not restrict results to the configured `CAIS_DATABASES` set. The comment claims the init script limits visibility, but that is an external assumption rather than an enforced boundary.

**Failure scenario:** The migrator has access to an operational or manually created database outside `CAIS_DATABASES`. `apply()` treats it as managed, scans every table, revokes/grants privileges there, and may fail the entire migration because unrelated tables are unclassified.

**Suggested fix:** Derive the managed-schema list from the authoritative configured allowlist and validate that each configured database exists and is accessible. Do not use all visible schemas as the policy scope.

### High — `apply()` now mutates every visible database

**File:** `modules/Platform/src/Database/TableGrants.php:97–131`

The new loop applies grants and revokes for every schema returned by `schemas()`. This is a materially broader mutation scope than the previous implementation.

**Failure scenario:** A database becomes visible to the migrator because of a deployment privilege change or shared MySQL instance. A normal migration silently revokes table privileges for the configured accounts on that database, or throws after partially changing it because it contains unclassified tables/views.

**Suggested fix:** Restrict the loop to an explicit, validated managed-database allowlist. Add a regression test proving an unrelated visible schema is neither changed nor included in verification.

### Medium — `annotation_body()` can fail when no diagnostic lines match

**File:** `scripts/ci/lib.sh:40–42`

With `set -euo pipefail` in the caller, the first pipeline can return nonzero when `grep` finds no matching error line. That can cause the annotation function or command substitution to fail instead of producing the fallback tail.

**Failure scenario:** A backend command fails with output containing neither `exception`, `error`, `denied`, `cannot`, `failed`, nor the cross marker. The failure trap attempts to build an annotation, but the diagnostic pipeline exits nonzero and the annotation is incomplete or absent.

**Suggested fix:** Make the filtering pipelines explicitly tolerant of no matches, for example by appending `|| true` to the diagnostic branch or restructuring with `awk`. Add a no-diagnostic-lines test.

### Medium — Principal matching is coupled to one exact `SHOW GRANTS` rendering

**File:** `modules/Platform/src/Database/TableGrants.php:216–233`

The parser requires the exact principal suffix `TO \`user\`@\`host\`` and exact `GRANT USAGE` formatting. MySQL output formatting and account quoting should be treated as a parser contract and tested explicitly.

**Failure scenario:** MySQL returns an equivalent grant using a different valid account representation or spacing. The code records it as an unexpected grant, causing migrations to fail despite correct privileges.

**Suggested fix:** Parse the principal into user/host components, normalize quoting, and compare normalized values. Add fixtures for the supported MySQL `SHOW GRANTS` forms.

## 3. Recommendation

**No.**

The branch is less safe than its parent because `apply()` now mutates every schema visible to the migrator while `schemas()` does not enforce the configured managed-database boundary. That can alter unrelated databases or fail migrations after partial grant changes.

## 4. What I did not check

I did not run tests, PHPStan, Pint, Bash scripts, Docker, migrations, SQL, CI workflows, or network calls. I did not inspect the full init-script implementation, runtime MySQL privileges, compose configuration, or GitHub Actions behavior.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| High 1 + High 2 | "Managed" meant every database the migrator can see; `apply()` could change unrelated databases | High | **Accept** | Managed databases are now the explicit list `database.grants.databases`, set by compose (`DB_GRANT_DATABASES`) from the same `.env` values as the MySQL init script's `CAIS_DATABASES`; overriding `DB_DATABASE` in a container does not change it. Each listed database must exist and be visible, and the connection's own database must be listed. Regression test: with a probe table and grant in a database outside the list, `apply()` fails verification and leaves that grant untouched. |
| Medium 3 | `annotation_body` with no error lines under `pipefail` | Medium | **Accept** | `|| true` on both filters; test with a log that has no error lines. (Command substitution does not inherit `errexit`, so the output was already produced; the change makes it explicit.) |
| Medium 4 | Principal matching tied to one `SHOW GRANTS` rendering | Medium | **Reject (documented)** | The comparison fails closed: a different rendering makes the migration stop, never grants access. MySQL 8.4 renders `` `user`@`host` `` consistently, and every CI run checks it against the pinned MySQL version (ADR-0003); an upgrade that changed it would fail CI before reaching `main`. Normalising alternative forms would add parsing surface for no current case. |
