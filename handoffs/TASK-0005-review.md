# TASK-0005 — Codex re-review of the TASK-0004 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (19:19:48–19:20:27 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19367-6409-7312-9566-eff0dbdae5fe |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0005.prompt.md`, branch `fix/task-0003-hardening` @ `c407212` (clean tree) |
| Invoked by | Claude Code, **at the Product Owner's request** ("approved. proceed." in reply to Claude's offer to run it read-only) |
| Raw output SHA-256 | `0b787e2c2780c76748bf2456b3c4c00917142fc0284b966ce9c0db4917cdfbe6` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. Per-finding verdicts

- **N1 — Complete, with a new residual gap.**  
  Cleanup now enumerates all `mysql.db` rows and checks `SHOW GRANTS` (`docker/mysql/init/10-accounts.sh:66-84,104-137`). The exact allowlist is not actually exact for app/retention table grants; see New Finding 1.

- **N2 — Complete.**  
  Runtime services receive a read-only tmpfs over `.secrets` (`compose.yaml:18-22,103-106`). CI checks PHP filesystem/environment access and verifies every repository-mounted service has the masking tmpfs (`scripts/ci/backend.sh:35-51`).

- **N3 — Complete.**  
  Every `.env*.example` line is restricted to blank/comment/single-line assignment syntax, and non-empty values must be explicitly allowlisted (`scripts/ci/secrets.sh:15-32`, `scripts/ci/env-example-values.txt:1-52`). Gitleaks remains an additional detector (`scripts/ci/secrets.sh:34-49`).

- **init-env missing tests — Complete.**  
  Five scenarios cover fresh creation, idempotence, old-layout migration, conflicting values, and empty old values (`scripts/ci/init-env-test.sh:13-48`). Conflict handling is implemented in `scripts/dev/init-env.sh:48-66`.

## 2. New findings

### Finding 1 — High: app/retention table-grant allowlist is broader than policy

**File:** `docker/mysql/init/10-accounts.sh:123-125`

The purported exact `SHOW GRANTS` validation accepts any combination of `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on any table in any configured database:

```bash
[[ $line =~ ^GRANT\ $dml(,\ $dml)*\ ON\ \`($db_alt)\`\.\`[A-Za-z0-9_]+\` ...
```

It does not verify that the table exists, is classified appropriately, or has the configured privilege set.

**Failure scenario:** A stale or manually added grant such as `DELETE ON cais.migrations` or `UPDATE ON cais.append_only_evidence` survives account initialization because the table exists and the broad regex accepts it. An app-excluded table can also receive DML privileges without failing the assertion.

**Safe fix:** Generate the allowed table-level grant lines from the configured `TableGrants` policy and existing tables, or reject all app/retention table grants in `10-accounts.sh` and require `db:apply-grants` to establish and verify them. Assert exact table-to-privilege mappings, including no grants on `app_excluded` or unclassified tables.

### Finding 2 — Medium: CI annotations are not secret-redacted

**File:** `scripts/ci/backend.sh:16-24`

The failure annotation includes selected log lines verbatim. It filters formatting and some stack-trace lines but does not redact values matching passwords, tokens, connection strings, or arbitrary secret-like output.

**Failure scenario:** A failing dependency check, exception, or diagnostic command prints a URL, DSN, environment value, or provider token. The value is copied into the GitHub workflow annotation and may be visible to users with repository/workflow access.

**Safe fix:** Do not include arbitrary command output in annotations. Emit only a fixed step name and exit code, or apply a robust redaction pass for known secret values before annotation. Keep detailed diagnostics in protected CI logs.

## 3. Missing tests

- Seed an existing table with unauthorized app/retention grants and verify `10-accounts.sh` removes or rejects them.
- Verify app grants exactly match each table’s configured class:
  - mutable: `SELECT, INSERT, UPDATE, DELETE`;
  - append-only: `SELECT, INSERT`;
  - app-excluded/unclassified: none.
- Verify grants on `migrations`, newly created tables, and tables outside the configured policy cannot survive account hardening.
- Add a CI annotation test containing synthetic password/token/DSN strings and verify the generated annotation contains no secret values.
- Verify every runtime container (`php`, `horizon`, `scheduler`, and `node`) has neither secret files nor service-secret environment variables; current backend automation directly probes only `php` and structurally checks the mount masking.

## 4. Recommendation

**No.** The branch should not merge until the table-grant allowlist is narrowed to the actual policy. Annotation redaction should also be fixed or the annotation content reduced to non-sensitive fixed metadata.

## 5. What I did not check

I did not execute tests, Docker commands, migrations, SQL, CI workflows, or network calls. I did not modify files. I reviewed the requested diffs and the relevant changed files, but did not exhaustively inspect unrelated application routes, jobs, deployment manifests, or external repository settings.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

Both findings are valid; the merge was held until they were fixed. Fixes are on `fix/task-0003-hardening`.

| # | Finding (short) | Severity | Decision | Evidence / rationale | Fix |
|---|---|---|---|---|---|
| 1 | App/retention table-grant allowlist broader than the policy | High | **Accept** | Confirmed: the regex accepted any DML on any table of a configured database, e.g. `DELETE` on `migrations`. `db:apply-grants` corrected it on the next migrate, but nothing checked it. | `10-accounts.sh` now removes **every** table grant of the managed accounts; the allowlist is exact (`USAGE` only for app/retention). Table grants are owned by `TableGrants`, which after applying reads each account's own `SHOW GRANTS` and compares them with the policy table by table (`verify()`); any extra, missing, column-level or `WITH GRANT OPTION` grant fails the migration. Found while testing: MySQL keeps grants on dropped tables, so a re-created table inherited them; `apply()` now revokes those too. Tests: seeded `DELETE` on `migrations` and `UPDATE` on an append-only table are detected and removed; dropped-and-recreated table gets no access; `accounts-test.sh` seeds table grants on the main schema. |
| 2 | CI annotations not redacted | Medium | **Accept** | Correct: the repository is public, so annotations are public. | `scripts/ci/lib.sh`: every password/secret/key/token value from the env files is registered with `::add-mask::` (the runner hides it in logs and annotations), and the annotation text is redacted (env-file values, DSN passwords, `key=value` secrets). `scripts/ci/redact-test.sh` runs in CI with synthetic values. |
| Missing test: per-container probes for `horizon`, `scheduler`, `node` | — | — | **Accept in part** | They are built from the same compose anchor or the same overlay; CI checks the rendered compose config for every repo-mounting service. | No change; a per-container probe would add three container starts for the same configuration. |
