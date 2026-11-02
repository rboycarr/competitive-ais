# TASK-0054 — Codex verification of the TASK-0053 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (14:00:33–14:01:35 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19c8f-d8dd-78f1-907e-66af147e97f1 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0054.prompt.md`, branch `feature/pennant-feature-flags` @ `32ca359` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `f6842eb1fc4b97e91e16385362e2354aadd8243715a6d57209da6b91d8a97de3` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0053 findings

1. **High — app-account writes to `features`: Confirmed fixed.**  
   `features` is now `read_only` in `config/database.php`, so the application account cannot update, insert, or delete rows. The switch path explicitly uses `migrator`.

2. **High — concurrent switches: Confirmed fixed.**  
   `Features::switch()` locks the existing global row with `lockForUpdate()`, rereads state, updates the row, and writes the audit event on the same migrator transaction.

3. **Medium — concurrent first initialization: Confirmed fixed.**  
   The migration creates the known global row with `false`, and `switch()` rejects missing rows. The application cannot create rows because it lacks write privileges.

4. **Medium — Pennant mutation and purge surfaces: Partially resolved.**  
   `pennant:purge` is blocked, and application-account Pennant writes fail. However, the migrator service has write access and can still resolve Pennant’s manager/store and call `activate()` or `deactivate()` directly, bypassing `Features::switch()` and its audit event.

5. **Low — rollback loses state: Confirmed fixed for the stated contract.**  
   `down()` refuses to drop the table while any value is not exactly JSON `false`. With all flags off, dropping the table loses no active decision.

## 2. New findings

### Critical — credential-bearing file contains a non-placeholder password

File: `.secrets/migrator.env:4`

The file contains a concrete migrator password rather than an empty or placeholder value. Although it is not tracked by Git, it is present in the workspace and appears credential-like.

Scenario: the workspace or ignored secret file is copied, backed up, uploaded, or exposed to another process, granting database migration/write privileges.

Safe fix: rotate the credential immediately, replace the local value with a placeholder, verify `.secrets/` is excluded from all backups and artifacts, and ensure setup generates secrets rather than storing them in the repository workspace.

### High — direct Pennant mutation remains possible in the migrator service

Files: `modules/Platform/src/Support/Features.php:67–80`, `modules/Platform/src/PlatformServiceProvider.php:90–96`

The database privilege boundary prevents app-account bypasses, but the migrator process itself has write access. Any command or code loaded in that service can call Pennant’s store directly and change `features` without an audit event. Blocking only `pennant:purge` does not enforce the complete audited-write guarantee.

Scenario:

```php
app(FeatureManager::class)->store()->globally()->activate('collection');
```

when executed in the migrator container changes the flag without `feature.switched`.

Safe fix: isolate feature switching into a narrowly scoped command/service, remove or disable Pennant mutation access from general migrator command execution, or add a database-level procedure/trigger requiring the audited path. Add a test proving direct mutation fails even with migrator configuration present.

### Medium — `SourceTest` no longer tests the production database-backed flag path

File: `modules/Sources/tests/Feature/SourceTest.php:101–114, 307–313`

The test now forces Pennant to use the in-memory array store. This avoids transaction visibility problems, but it means `SourceService` is no longer tested against the actual `features` table, read-only app grant, or database-backed value semantics.

Scenario: production configuration accidentally selects the wrong Pennant store, uses an incorrect scope serialization, or fails to read a switched database value; `SourceTest` still passes because it uses an unrelated in-memory store.

Safe fix: retain a separate database-backed integration test using committed migrator writes and a fresh application/connection, or run the relevant source assertions in a process that reads the committed database state.

### Low — concurrency test does not reliably prove cache freshness

File: `modules/Platform/tests/Feature/FeaturesTest.php:112–134`

The parent process may have a cached Pennant value from before the child processes run. The final assertion calls `enabled()` without explicitly flushing the parent cache. The test can therefore assert a stale value or pass accidentally when the stale value matches the final value.

Safe fix: call `flushCache()` before the final database/state assertion, and assert the raw stored JSON value independently of Pennant’s cache.

## 3. Recommendation

**Yes after fixes.**

The original privilege, locking, initialization, and rollback issues are substantially corrected. The direct migrator-side Pennant mutation path still violates the stated “audited feature changes only” guarantee, and the source integration coverage should be restored. The credential-bearing workspace file also requires immediate rotation/remediation.

## 4. What I did not check

- I did not run tests, migrations, Laravel commands, static analysis, or Docker/Compose validation.
- I did not inspect the installed Laravel Pennant vendor implementation.
- I did not inspect unrelated application modules or deployment environments beyond the requested files.
- I did not verify the actual MySQL grants at runtime; conclusions are based on the reviewed configuration and grant-management code.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

TASK-0053 #1, #2, #3 and #5 confirmed fixed; #4 partly, completed under "High" below. Recommendation "yes after fixes"; fixed in the next commit.

| Finding | Disposition |
|---|---|
| Critical: `.secrets/migrator.env` holds a real password | **Not a branch defect; reported to the PO.** The file is git-ignored, never committed (`git ls-files .secrets` is empty), generated per machine by `scripts/dev/init-env.sh` for the local Docker MySQL, which listens on 127.0.0.1 only, and hidden inside every container that mounts the repository (TASK-0004 N2). The finding came from Codex opening the file (`Get-Content .secrets/migrator.env`), so the local migrator password was in this review session's context; the value is not in this record. Codex's local run log, which held it, was deleted. Prevention: AGENTS.md now forbids opening `.env`, `.env.*` other than `*.example`, and `.secrets/`. Changing the local migrator password is the PO's decision. |
| High: Pennant writes in the migrator service bypass the audit | **Fixed by making such a change ineffective and visible.** The migrator account owns the schema, so no database rule can bind it; instead a flag counts as on only when its stored value is `true` **and** its last `feature.switched` audit row says on (`Features::enabled`, one indexed lookup, made only when the stored value is on). A row changed any other way reads as off; `feature:status` shows "Matches audit trail: NO" and exits 1; `feature:switch` repairs it with an audited change. Tests: an unaudited "on" reads as off and is reported, `/sources` refuses automated methods, and the next audited switch repairs it. |
| Medium: `SourceTest` no longer tests the database-backed flag | **Fixed.** New `SourceCollectionFlagTest` (DatabaseMigrations): with the flag off, `/sources` refuses `rss`; after `feature:switch collection on`, the form offers every method and `rss` is accepted; an unaudited "on" is refused again. `SourceTest` keeps the in-memory value-rule cases. |
| Low: the concurrency test could read a stale cache | **Fixed.** Pennant's cache is flushed before the final check, and the stored JSON is asserted directly. |
