# TASK-0004 — Codex re-review of the TASK-0003 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-10-31 (15:00:42–15:01:15 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1927a-2c75-75a3-b029-441184a3db65 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0004.prompt.md`, branch `fix/task-0003-hardening` @ `64c1c60` (clean tree) |
| Invoked by | Claude Code, **at the Product Owner's request** ("proceed. go." in reply to Claude's offer to run it read-only) |
| Raw output SHA-256 | `5dbaa71556e8d0b806e679ab873d8287837326023947a946da6b8089a6783f52` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0003 verdicts

1. **Critical — Partial.**  
   `compose.yaml:16-18` bind-mounts the repository into every PHP service, including `php`, `horizon`, and `scheduler`. Since these containers run as the checkout owner, runtime code can read `.env.migrator` directly from `/var/www/html/.env.migrator`, even though `env_file` is only configured for `migrator` at `compose.yaml:71-75`. The environment-variable isolation is fixed, but the filesystem isolation is not.

2. **Complete.**  
   `config/database.php:185-189` documents strict classification. `TableGrants` now rejects unclassified and duplicate classifications rather than defaulting to mutable access. Evidence: `modules/Platform/src/Database/TableGrants.php` classification and exception paths.

3. **Complete, with residual concern noted below.**  
   `TableGrants` now enumerates views, revokes grants, and fails when views exist. `docker/mysql/init/10-accounts.sh:70-74` removes `CREATE VIEW` and `SHOW VIEW` from the migrator. Stale table-grant cleanup is present at `docker/mysql/init/10-accounts.sh:84-93`.

4. **Partial.**  
   Role, proxy, and default-role cleanup exists at `docker/mysql/init/10-accounts.sh:82-98`. However, the final assertion at `docker/mysql/init/10-accounts.sh:100-117` does not comprehensively detect all remaining global or schema-level privileges; see New Finding 1.

5. **Complete in design, not independently verified.**  
   `modules/Platform/tests/Feature/DatabaseAccountsTest.php` now iterates configured append-only tables and tests the probe plus existing configured tables. This should automatically cover future tables after their migrations exist. The test skips configured tables that do not yet exist, so future migrations still depend on CI executing the test after migration.

6. **Partial.**  
   The architecture scan at `tests/Architecture/MigratorIsolationTest.php:18-26` rejects most source references, and the feature test checks refusal without credentials. However, neither test detects runtime access to `.env.migrator` through the shared bind mount. This is the practical bypass described in verdict #1.

7. **Partial.**  
   `compose.yaml:159-168` removes MySQL secrets from SeaweedFS and gives it only S3 credentials. However, `.env.s3` is also visible through the shared PHP bind mount at `compose.yaml:16-18`, exposing admin and anchor keys to runtime PHP code. See New Finding 2.

8. **Complete for the rendered file.**  
   `compose.yaml:167-168` places `/run/s3config` on a mode-700 tmpfs, and `docker/seaweedfs/start.sh:20-23` writes the rendered configuration there instead of `/tmp/s3.json`.

9. **Partial.**  
   The broad example-file exemption was removed at `.gitleaks.toml:10-13`, and `scripts/ci/secrets.sh:11-26` adds value checks. However, the new value-shape check is incomplete for multiline, quoted, whitespace-containing, or non-alphanumeric secret formats; see New Finding 3.

10. **Complete for the two identified CI images.**  
    `scripts/ci/secrets.sh:9` and `scripts/dev/verify-object-store.sh:9` use immutable digests for Gitleaks and AWS CLI. Other Compose images remain tag-pinned, but that was outside the accepted specific fix.

## 2. New findings

### Finding 1 — High: account-hardening assertion misses privileges outside the configured databases

**File:** `docker/mysql/init/10-accounts.sh:53-56,100-117`

The reset revokes database-level privileges only for databases listed in `CAIS_DATABASES`, and the final assertion checks roles, proxies, column/table grants, and selected global privilege columns, but does not inspect `mysql.db` for unexpected grants on other schemas or all global privilege columns.

**Failure scenario:** An earlier deployment grants `CREATE`, `EVENT`, `TRIGGER`, or schema-level access on another database to a managed account. Re-running the initialization script leaves that privilege active and the final assertion passes.

**Safe fix:** Revoke schema privileges for every database found in `mysql.db` for managed users, or explicitly enumerate and fail on any `mysql.db` row outside the approved policy. Assert all relevant `mysql.user` privilege columns, including `Create_view_priv`, `Create_routine_priv`, `Alter_routine_priv`, `Trigger_priv`, `Event_priv`, `Create_tablespace_priv`, and role-management privileges.

### Finding 2 — High: shared source bind mount exposes all segregated env files to runtime PHP

**Files:** `compose.yaml:16-18`, `.env.migrator`, `.env.s3`

The `x-app` volume mount exposes the repository, including `.env.migrator` and `.env.s3`, to `php`, `horizon`, and `scheduler`. The containers run with the configured development UID, so application code can read these files directly.

**Failure scenario:** A compromised route, Horizon job, package, or diagnostic endpoint executes:

```php
file_get_contents('/var/www/html/.env.migrator');
file_get_contents('/var/www/html/.env.s3');
```

It obtains the migrator password, S3 admin key, and audit-anchor key despite the `env_file` separation.

**Safe fix:** Do not bind-mount secret files into runtime containers. Use a dedicated source mount excluding env files, Docker secrets, or production-built images. At minimum, mount `.env.migrator` and `.env.s3` only into the services that require them and add an automated compose/configuration test proving runtime containers cannot read them.

### Finding 3 — Medium: example-file secret detection still has bypasses

**File:** `scripts/ci/secrets.sh:11-26`

The supplemental check only examines single-line values matching a restricted character set and only detects secret-like key names through a limited regex. It can miss multiline values, values containing spaces or Unicode, and secrets under keys whose values do not match the assumed pattern.

**Failure scenario:** An example file contains:

```dotenv
ROTATION_SALT="first-line
second-line-secret"
```

or a token containing spaces/colon characters. The current checks may not flag it, depending on Gitleaks rule matching.

**Safe fix:** Parse every assignment in all `.env*.example` files and require values to be empty or explicitly approved non-secret placeholders. Reject continuation lines and multiline assignments. Keep Gitleaks as an additional detector, not the primary policy enforcement.

## 3. Missing tests

- Verify `php`, `horizon`, and `scheduler` cannot read `.env.migrator` or `.env.s3` from their mounted filesystem.
- Verify the Compose-expanded runtime services contain neither migrator nor S3 admin/anchor secrets in environment variables or mounted files.
- Seed managed accounts with privileges on an unlisted database and with less-common global privileges, then verify initialization revokes or rejects them.
- Test rerunning `init-env.sh` when both old and new env files contain different non-empty values.
- Test rerunning `init-env.sh` when the old source key exists but is empty and the destination already contains a generated secret.
- Test multiline, quoted, whitespace-containing, Unicode, and unconventional-key secret values in example files.
- Test duplicate or unexpected `mysql.db`, `mysql.columns_priv`, and global privilege rows for all managed users.
- Test all runtime entry points—not only Artisan commands—against migrator connection resolution and filesystem secret access.

## 4. What I did not check

I did not execute tests, Docker commands, migrations, SQL, CI workflows, or object-store verification. I did not make network calls or modify files.

I reviewed the requested branch diff, the required instructions and task briefs, and the changed files relevant to the stated review focus. I did not independently inspect every unchanged application route, queue job, scheduler definition, migration, production deployment manifest, or external branch-protection configuration.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-10-31)

All three new findings and the "partial" verdicts are valid. The root cause of #1, #6 and #7 being partial is the same: the repository bind mount exposes the separated env files to runtime containers. Claude Code had documented this as a local caveat instead of closing it; it is closable and is now closed. Fixes are on the same branch, `fix/task-0003-hardening`.

| # | Finding (short) | Severity | Decision | Evidence / rationale | Fix |
|---|---|---|---|---|---|
| N2 (+ verdicts #1, #6, #7) | Bind mount lets runtime PHP read `.env.migrator` and `.env.s3` | High | **Accept** | Confirmed: `compose.yaml` mounts `.` into `php`, `horizon`, `scheduler`, `migrator`, `node`; those files sit at the repo root. | Service-only secrets move into a gitignored `.secrets/` directory; every container that bind-mounts the repo gets an empty read-only tmpfs over `.secrets`, so the files are invisible inside containers (Compose still reads them on the host for `env_file`). CI asserts that runtime containers see no secret files and no migrator/S3-admin variables. |
| N1 (+ verdict #4) | Account assertion misses other schemas and some global privileges | High | **Accept** | Confirmed: revocation covered only `CAIS_DATABASES`; the final query checked a subset of `mysql.user` columns. | Revoke schema privileges on every database found in `mysql.db` for managed accounts; replace the column-based assertion with an exact check of `SHOW GRANTS` per account (allowed lines only: `USAGE`, the migrator's and readonly's exact schema grants, table-level grants for app/retention). Any other line, including dynamic global privileges, roles and proxies, fails. CI test seeds unexpected privileges and expects them gone. |
| N3 (+ verdict #9) | Example-file check bypassable (multiline, spaces, odd formats) | Medium | **Accept** | Correct. | Example files are parsed line by line: only blank lines, comments and single-line `KEY=VALUE` are allowed; every non-empty value must appear in an explicit allowlist (`scripts/ci/env-example-values.txt`). gitleaks stays as an extra detector. |
| Missing tests: `init-env.sh` with conflicting values | — | — | **Accept** | Correct: a key present with different non-empty values in old and new files was overwritten. | `init-env.sh` refuses to guess: different non-empty values stop with an error; an empty old key is dropped. Shell test in CI covers both cases. |
| Missing tests: runtime entry points beyond Artisan | — | — | **Accept in part** | No routes, jobs or schedules exist yet besides the framework defaults. | Covered structurally: the connection is undefined in runtime containers and their filesystems hold no migrator secret (CI check). Entry-point tests are added with the first routes/jobs. |
| Verdict #10: other Compose images tag-pinned | — | — | **Defer to PO** | Outside the accepted fix. | Proposal recorded: pin all images by digest with Renovate/Dependabot updates (`docs/11` open item). |
