# TASK-0006 — Codex verification of the TASK-0005 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (19:37:24–19:37:47 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19377-8203-7ed0-b48f-358451f26f30 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0006.prompt.md`, branch `fix/task-0003-hardening` @ `41e748a` |
| Invoked by | Claude Code, under the Product Owner's 2026-11-01 approval of the pre-merge review ("approved. proceed.") |
| Raw output SHA-256 | `4a89ac8771b4f66d1eca2080e7f4f294410e0666b25f9485af285ee4ea539fd0` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0005 finding verdicts

### Finding 1 — Partial

The init-script allowlist is now exact for managed accounts: `docker/mysql/init/10-accounts.sh:102-129` rejects unexpected table, column, role, proxy, schema, and global grants. Cleanup also revokes all managed-account table grants, including grants on dropped tables: `:79-82`.

However, `TableGrants::verify()` does not enforce the policy across all schemas. `modules/Platform/src/Database/TableGrants.php:198-203` explicitly tolerates grants on other schemas when they contain only mutable DML privileges. Thus, an out-of-band `SELECT` or `DELETE` grant on another schema can remain undetected by `verify()`.

### Finding 2 — Partial

Secret masking and redaction were added in `scripts/ci/lib.sh:4-27`, and the backend invokes them at `scripts/ci/backend.sh:11-12,22-28`. A redaction test was also added at `scripts/ci/redact-test.sh:17-31`.

But the annotation escaping command is malformed at `scripts/ci/backend.sh:27`:

```bash
| sed':a;N;$!ba;s/%/%25/g;s/\n/%0A/g'
```

The missing space means the shell attempts to execute a command named `sed:a;...`, so the failure-annotation path itself fails. The added redaction test does not exercise this annotation path.

## 2. New problems

### High — `verify()` permits unauthorized grants on other schemas

**File:** `modules/Platform/src/Database/TableGrants.php:128-132,198-203`

**Failure scenario:** The app account receives `SELECT` or `DELETE` on `other_db.sensitive_table`. `SHOW GRANTS` parses successfully, and because the privileges are contained in `MUTABLE`, `verify()` ignores the grant. The account retains access outside the managed schema.

**Fix:** Treat every table grant outside `$schema` as unexpected, or explicitly compare all grants against a global account policy. Do not silently tolerate cross-schema grants.

### Medium — failure annotations are broken

**File:** `scripts/ci/backend.sh:27`

**Failure scenario:** A backend step fails under GitHub Actions. The `annotate` trap reaches the malformed `sed` invocation, producing a command-not-found failure instead of a properly escaped annotation.

**Fix:** Add the missing space:

```bash
| sed ':a;N;$!ba;s/%/%25/g;s/\n/%0A/g'
```

Add a test that invokes the annotation-generation path, not only `redact()` directly.

### Medium — `SHOW GRANTS` parsing is account-identity weak

**File:** `modules/Platform/src/Database/TableGrants.php:192-205`

The parser accepts any principal after `TO \S+`; it does not verify that the returned grant is actually for the configured app or retention account. This relies entirely on the temporary connection identity and server behavior.

**Failure scenario:** A misconfigured or substituted connection returns grants for a different principal. Those grants are treated as the target account’s privileges and may produce a false verification result.

**Fix:** Parse and validate the `TO` principal against the expected configured account and host, and fail closed on mismatch.

### Low — connection cleanup is not exception-safe

**File:** `modules/Platform/src/Database/TableGrants.php:184-207`

If `SHOW GRANTS` throws, `purge("grants-check-$role")` is skipped. Repeated migration or verification attempts can retain stale temporary connection state.

**Fix:** Wrap the query loop in `try/finally` and always purge the temporary connection.

### Low — redaction coverage is narrower than its comment implies

**File:** `scripts/ci/lib.sh:4-8,25-27`

`secret_values()` only recognizes uppercase variable names containing `PASSWORD`, `SECRET`, `KEY`, or `TOKEN`, and only values of six or more characters. It does not register arbitrary environment secrets, lowercase names, multiline/encoded values, or secrets supplied outside the two env files.

**Failure scenario:** A CI-only credential or a variable such as `DB_PASS` is printed in a failure log and is not masked by `mask_secrets()`. The generic regex may redact some forms, but not all representations.

**Fix:** Prefer fixed, non-diagnostic annotations; otherwise explicitly pass all CI secret values to masking and test representative alternate variable names and formats.

### Low — the dropped-table test does not verify retention cleanup

**File:** `modules/Platform/tests/Feature/DatabaseAccountsTest.php:182-193`

The test validates only the app account’s stale grant. The implementation intends to clean stale grants for both app and retention accounts, but retention is not seeded or asserted.

**Fix:** Seed stale app and retention grants on a dropped table and verify both are removed before recreation.

## 3. Recommendation

**No.**

The branch should not merge until the malformed annotation command is fixed and `TableGrants::verify()` rejects unauthorized grants on other schemas. The temporary-connection cleanup and broader redaction tests should also be addressed before relying on this hardening.

## 4. What I did not check

I did not execute tests, Docker commands, migrations, SQL, CI workflows, or network calls. I did not inspect unrelated application code, the full compose configuration, MySQL runtime behavior, or external GitHub Actions settings.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

All six are valid; the merge stayed on hold. The broken `sed` is a real regression from Claude Code's edit (a trailing space was lost), and the redaction test did not exercise that path.

| # | Finding (short) | Severity | Decision | Fix |
|---|---|---|---|---|
| 1 | `verify()` tolerated DML grants on other databases | High | **Accept** | Managed databases are the ones the migrator can see (the init script limits it to `CAIS_DATABASES`). `verify()` checks every managed database against the policy and rejects grants on any other database. Because the databases share one policy, `apply()` now applies it to all of them; otherwise a drift in one blocked migrations in the other (found while testing). Test: a grant on a probe table in the main database is reported. |
| 2 | Annotation `sed` missing a space; path untested | Medium | **Accept** | Annotation building moved to `annotation_body` in `scripts/ci/lib.sh`; the backend trap and `redact-test.sh` call the same function. The test also checks `%` escaping and a single-line result. |
| 3 | `SHOW GRANTS` principal not checked | Medium | **Accept** | Each line must end with the configured account (`TO \`user\`@\`host\``), including the `USAGE` line; anything else is a problem. |
| 4 | Temporary connection not purged on error | Low | **Accept** | `try/finally` around `SHOW GRANTS`. |
| 5 | Redaction keyed on a narrow name pattern | Low | **Accept** | Any key containing PASS, SECRET, KEY, TOKEN, SALT, CREDENTIAL or AUTH (any case), and every entry of a `.secrets/` file, is masked and redacted; the test covers `DB_PASS`, a lowercase name and a `.secrets/` entry. All CI credentials come from those files; `GITHUB_TOKEN` is masked by the runner. |
| 6 | Dropped-table test ignored retention | Low | **Accept** | The test drops the append-only probe (held by both accounts) and checks both stale grants are reported, removed, and not inherited by a re-created table. |
