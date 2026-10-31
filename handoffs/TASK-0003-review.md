# TASK-0003 — Codex review (verbatim)

| Field | Value |
|---|---|
| Date | 2026-10-31 (14:31:28–14:32:12 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1925f-686a-79b3-8858-ead021adc6bf |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0003.prompt.md`, reviewing `main` @ `28ca5c7` |
| Invoked by | Claude Code, **at the Product Owner's explicit request** in this session (exception to ADR-0009's manual-run default) |
| Raw output SHA-256 | `476b7db9618dcdb9abd6d3bd5bc8c944e9b86865a08d4c945debf4c73e0d18e1` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Critical — migrator credentials are available to web, Horizon, and scheduler code**

   **Files:** `config/database.php:68-86`, `compose.yaml:6-23,42-64`

   The shared PHP container runs web requests, queued jobs, and the scheduler, while Laravel loads `DB_MIGRATOR_PASSWORD` from the same `.env`. Any compromised route, job payload, deserialization path, or malicious application code can call `DB::connection('migrator')` or invoke `db:apply-grants`. That account has schema DDL and `WITH GRANT OPTION` on lines 63–65 of `docker/mysql/init/10-accounts.sh`, allowing schema changes and privilege changes.

   **Scenario:** An attacker achieves PHP code execution or controls a queued job. The code connects through `migrator`, creates a view/table, changes append-only tables, or grants the application account additional privileges.

   **Fix:** Remove migrator credentials from runtime web/worker/scheduler containers. Run migrations and grant application as a separate deployment/admin job with an isolated secret. Explicitly deny or fail closed if application code attempts to resolve the migrator connection. Add separate compose services/users for web, workers, scheduler, and migration administration.

2. **High — grant policy defaults every unlisted table to mutable DML**

   **Files:** `config/database.php:181-205`, `modules/Platform/src/Database/TableGrants.php:51-56`

   Any base table not explicitly listed in `app_excluded` or `append_only` receives `SELECT, INSERT, UPDATE, DELETE`. This is unsafe for tables created outside migrations, newly introduced audit/evidence tables, or tables whose append-only classification is omitted.

   **Scenario:** A new append-only table is deployed before its policy entry is added. `apply()` grants the application account `UPDATE` and `DELETE`, violating the append-only requirement.

   **Fix:** Default to no application access for unknown tables. Require an explicit mutable or append-only classification, and fail migration/grant application when a table is unclassified.

3. **High — views and stale non-table grants are outside reconciliation**

   **Files:** `modules/Platform/src/Database/TableGrants.php:42-45,50-66`

   `plan()` only enumerates `TABLE_TYPE = 'BASE TABLE'`. It neither revokes grants on views nor verifies that no grants remain on dropped/renamed objects. The migrator itself has `CREATE VIEW` and `SHOW VIEW` privileges.

   **Scenario:** A view is created or an old view retains application privileges. The reconciliation command reports success while the application can read data through an unreviewed view, potentially bypassing intended table-level restrictions.

   **Fix:** Reconcile `BASE TABLE` and `VIEW` objects separately. Explicitly deny application access to all views unless allowlisted, and audit/revoke grants on objects absent from the policy.

4. **High — account reset does not remove roles or unrelated privilege mechanisms**

   **File:** `docker/mysql/init/10-accounts.sh:38-48`

   The reset uses `REVOKE ALL PRIVILEGES` and `REVOKE GRANT OPTION`, but does not revoke granted roles, proxy privileges, or other account attributes. On an existing volume, an account can retain privileges granted through roles.

   **Scenario:** An earlier/interim deployment granted a role to `cais_app`. Re-running the script leaves the role active even though the script appears to reset privileges.

   **Fix:** Explicitly revoke all assigned roles and proxy privileges, or drop/recreate the managed users after verifying that recreation is safe. Add an audit query that fails if any managed account has roles or unexpected global/schema privileges.

5. **Medium — tests do not prove protection of the real append-only tables**

   **File:** `modules/Platform/tests/Feature/DatabaseAccountsTest.php:15-17,34-41,118-133`

   The tests use a synthetic probe table and compare only the privileges returned for tables discovered by the current policy. They do not exercise actual `audit_events`, `audit_checkpoints`, `evidence_custody_events`, `source_policies`, or `verification_reviews` behavior, and those tables may not yet exist.

   **Scenario:** A real append-only migration uses a different table name, is omitted from configuration, or is a view. The probe tests remain green while production grants permit mutation.

   **Fix:** Add migration fixtures for every append-only table and test `INSERT`, `SELECT`, `UPDATE`, `DELETE`, `TRUNCATE`, and DDL against each. Add a test that fails for any database object not explicitly classified.

6. **Medium — tests do not test migrator isolation from application execution**

   **Files:** `modules/Platform/tests/Feature/DatabaseAccountsTest.php:65-69`, `tests/TestCase.php:27-34`

   The test confirms that an Artisan migration switches to the migrator, but does not prove that HTTP handlers, jobs, scheduled commands, or normal application services cannot resolve or use that connection.

   **Scenario:** A future controller or queued job calls `DB::connection('migrator')`; all current tests still pass.

   **Fix:** Add architecture/static tests rejecting migrator connection usage outside a dedicated migration runner, and integration tests booting web/worker/scheduler processes without migrator credentials.

7. **Medium — SeaweedFS receives all service secrets, including admin and anchor keys**

   **File:** `compose.yaml:147-153`

   `seaweedfs` loads the entire `.env.services` file, which includes `S3_ADMIN_*` and `S3_ANCHOR_*`. Although the identity policy restricts the app key, compromise of the SeaweedFS process or inspection of its environment exposes the admin and audit-anchor credentials.

   **Scenario:** A vulnerability in SeaweedFS or a command execution path in that container reveals the environment and enables governance bypass or writes to `audit-anchor`.

   **Fix:** Pass only the six values required by `start.sh` to the SeaweedFS service, preferably through narrowly scoped Docker secrets. Keep admin credentials exclusively in the init/administration service and anchor credentials exclusively in the anchor writer.

8. **Medium — complete identity configuration is rendered into `/tmp`**

   **Files:** `docker/seaweedfs/start.sh:5-20`

   `/tmp/s3.json` contains every identity’s access and secret keys. `umask 077` protects it from ordinary users, but any process or code running as the container user, and diagnostics or crash collection, may read all credentials.

   **Scenario:** A compromised helper process in the SeaweedFS container reads `/tmp/s3.json` and obtains admin and anchor keys.

   **Fix:** Use a protected mounted secret/config file with only the identities needed by that service, or render directly into a private runtime directory with restrictive ownership and cleanup on shutdown.

9. **Medium — gitleaks exempts entire environment example files**

   **Files:** `.gitleaks.toml:5-7`, `scripts/ci/secrets.sh:9-16`

   The allowlist excludes the entire `.env*.example` files from gitleaks. The supplemental check only validates lines matching a limited secret-name regex. A credential embedded under an unexpected key, multiline value, comment, or nonstandard configuration field could bypass both checks.

   **Scenario:** A real token is added to an example file under a name not matched by the regex; gitleaks ignores the file and CI passes.

   **Fix:** Remove the broad path exemption. Add narrowly scoped rules for exact empty placeholder lines, or scan the files normally and separately suppress only known false-positive line patterns.

10. **Low — CI supply-chain inputs are tag-pinned rather than digest-pinned**

    **Files:** `scripts/ci/secrets.sh:19-20`, `scripts/dev/verify-object-store.sh:10`

    GitHub Actions are SHA-pinned, but the gitleaks and AWS CLI container images use mutable version tags. A registry tag can be repointed to different content.

    **Fix:** Pin external container images by immutable digest and document the digest update process.

## Missing tests

- Verify a web request, Horizon job, and scheduler task cannot load or use the migrator connection.
- Verify the migration runner is the only process/container receiving migrator credentials.
- Verify managed MySQL users have no roles, proxy privileges, global privileges, or unexpected schema grants after rerunning initialization on an existing volume.
- Verify unknown tables and views receive no application privileges.
- Verify dropped/renamed tables and views do not leave effective privileges.
- Test every real append-only table against insert/read success and update/delete/truncate/DDL denial.
- Test retention access against only the explicitly allowlisted table and all other append-only tables.
- Test malformed grant configuration, including unknown privileges, invalid account hosts, nonexistent policy tables, and duplicate classifications.
- Test that object-store service environments do not contain admin or anchor secrets where they are not required.
- Test that the application key cannot read or write every object-lock version operation, including delete markers, retention extension/reduction, and legal-hold changes outside `evidence`.
- Add CI tests proving a secret under an unconventional key or multiline value is detected.
- Add CI policy checks requiring immutable image digests and required GitHub status checks.

## Questions or assumptions for the Product Owner

- Is the application intentionally permitted to run arbitrary Artisan commands in the web, worker, or scheduler containers?
- Can migrations and grant reconciliation be moved to a separate deployment identity and service?
- Should unclassified tables fail closed, rather than defaulting to mutable DML?
- Are views permitted in the application schema, and if so, which are explicitly approved?
- Is SeaweedFS considered a trusted credential boundary, or must admin and anchor keys be excluded from its runtime environment?
- Are GitHub branch-protection rules requiring all CI jobs to pass configured outside this repository?

## What I did not check

I did not execute tests, Docker commands, SQL, migrations, CI, object-store verification, or network calls, per the read-only constraints. I reviewed only the requested files and design references. I did not inspect files outside the stated scope, including deployment infrastructure, actual migration contents, scheduler definitions, queue job implementations, or GitHub repository branch-protection settings.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-10-31)

Each finding was checked against the code on `main` @ `28ca5c7`. All ten are valid in substance; two are accepted only in part because an S3 server must hold the keys it verifies. Fixes are made on branch `fix/task-0003-hardening`, reviewed again by Codex on the diff, and merged only with Product Owner approval (`docs/12` §2).

| # | Finding (short) | Severity | Decision | Evidence / rationale | Fix |
|---|---|---|---|---|---|
| 1 | Migrator credentials reachable from web, Horizon, scheduler | Critical | **Accept** | Confirmed: one `.env` feeds every PHP process; `config/database.php` defines the `migrator` connection for all of them. A code-execution bug in any request or job could run DDL or GRANT. | Migrator credentials move out of `.env` into a migrator-only env file used by a dedicated one-shot `migrate` service; runtime containers get none. The `migrator` connection is only configured when its password is present, and resolving it outside the migration runner fails closed. Local caveat: the bind-mounted repo still lets a local process read files; production images are not bind-mounted (`docs/10` §3). |
| 2 | Unlisted tables default to mutable DML | High | **Accept** | Confirmed in `TableGrants::plan()` (`default => self::MUTABLE`). | Every table must be classified (`mutable`, `append_only`, `app_excluded`); an unclassified table gets no app access and makes `db:apply-grants` (and therefore the migration) fail. |
| 3 | Views and stale grants outside reconciliation | High | **Accept** | Confirmed: only `BASE TABLE` is enumerated; migrator holds `CREATE VIEW`. | Remove `CREATE VIEW`/`SHOW VIEW` from the migrator (no views are planned); reconciliation also enumerates views and fails if any exist; a root-run audit (init script) reports and revokes app/retention grants on objects that no longer exist. |
| 4 | Account reset ignores roles and proxy privileges | High | **Accept** | Confirmed: script revokes privileges and GRANT OPTION only. | Init script revokes every granted role and proxy privilege of the managed accounts, sets default role NONE, and fails if any remain. |
| 5 | Tests do not cover the real append-only tables | Medium | **Accept** | Correct: real tables arrive with P1-05+. | Data-driven test over every configured append-only table that exists (insert/select allowed; update/delete/truncate/DDL denied), so each new table is covered automatically; plus a test that every object is classified. |
| 6 | No test that application code cannot use the migrator | Medium | **Accept** | Correct. | Architecture test: the string `'migrator'` as a connection name may appear only in the Platform migration wiring; feature test: resolving the migrator connection without migrator credentials fails closed. |
| 7 | SeaweedFS receives all of `.env.services` | Medium | **Accept in part** | Correct that it receives unrelated secrets (MySQL root and readonly passwords). Not avoidable for the S3 keys: SeaweedFS must hold every identity's secret to verify SigV4 signatures. | SeaweedFS gets only the six S3 key variables (no `env_file`); admin keys stay out of every other service. The S3 server remains a trusted credential boundary (documented). |
| 8 | Identity config rendered into `/tmp` | Medium | **Accept in part** | Same limit as #7: the server must read the keys. | Render into a tmpfs directory with mode 700 instead of `/tmp`. |
| 9 | gitleaks exempts the whole example files | Medium | **Accept** | Correct: a secret under an unexpected key would pass. | Remove the path exemption; suppress only the specific false-positive pattern (empty `KEY=` lines); keep the empty-value check. Verified with a planted secret under an unconventional key. |
| 10 | CI images pinned by tag, not digest | Low | **Accept** | Correct. | Pin the gitleaks and AWS CLI images by digest (tag kept in a comment); digest pinning of all Compose images is proposed to the Product Owner with an update process (Dependabot/Renovate). |

**Questions answered by Claude Code (recommendation; Product Owner to confirm):** runtime containers may not run schema commands (separate migration runner); unclassified tables fail closed; views are not permitted; SeaweedFS is a trusted credential boundary; branch protection is pending the Product Owner.
