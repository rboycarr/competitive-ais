# TASK-0053 — Codex review of P1-30a (feature flags on Laravel Pennant) (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (12:59:56–13:01:15 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19c58-626a-7a93-aa86-9fd9c49645f4 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0053.prompt.md`, branch `feature/pennant-feature-flags` @ `ed49f49` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `49423eec5bd94d1f82d1c77a4f0a562400088b3f11a1c16e20fd8aef6ceb0b9a` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
Findings

1. **High — direct database writes can switch collection without an audit event**  
   File: `config/database.php:212–222`; `modules/Platform/database/migrations/2026_11_15_000100_create_features_table.php:14–26`

   The `features` table is classified as `mutable`, granting the application account `SELECT/INSERT/UPDATE/DELETE`. Any application code, SQL injection, compromised worker, or operational script using that account can update `value` to `true` without going through `Features::switch()` or `feature:switch`.

   Failure scenario: an unreviewed code path executes `DB::table('features')->where(...)->update(['value' => 'true'])`; automated collection becomes enabled with no `feature.switched` audit record.

   Safe fix: enforce the audited write boundary at the database level. Prefer a dedicated Pennant writer account/procedure or a controlled database trigger/privilege design that prevents ordinary app writes and permits only the audited switching path. At minimum, add an architecture/runtime test proving no production application path can write `features` outside the switch service, while documenting that database credentials remain a bypass.

2. **High — concurrent switches are not serialized and audits may not describe the final state**  
   File: `modules/Platform/src/Support/Features.php:45–67`

   `switch()` reads the current state, then activates/deactivates it without locking the row or using an atomic conditional update. Two concurrent switches can both observe the same state and each write successfully.

   Failure scenario: concurrent `feature:switch collection on` and `feature:switch collection off` both observe `false` (or both observe `true`), each records an audit event, and the final database state depends on commit/write order. The audit trail then cannot reliably establish which requested transition produced the final state.

   Safe fix: lock the global flag row with `SELECT ... FOR UPDATE` after ensuring the default row exists, or perform an atomic update under a unique-key-controlled transaction. Re-read the locked row before deciding whether to write and audit. Add concurrent on/off tests asserting a deterministic final state and transition history.

3. **Medium — first-read default insertion is not explicitly protected against concurrent initialization**  
   File: `modules/Platform/src/Support/Features.php:30–37, 45–55`; migration `...create_features_table.php:14–26`

   Pennant’s default resolver inserts the default row on first read. `switch()` starts a transaction, but the implementation does not itself ensure that the first-read insert is serialized with another first read or switch. Depending on Pennant 1.26.0’s database-store implementation, concurrent initialization may produce a duplicate-key exception or race with the switch transaction.

   Failure scenario: the first web request and `feature:switch collection on` arrive simultaneously. Both attempt to create the global row; one can fail on the unique key or make the switch path observe an inconsistent state.

   Safe fix: explicitly initialize known global rows in a migration/seed step, or handle the unique-key race and lock/re-read the row inside `switch()`. Add a first-read versus switch concurrency test.

4. **Medium — Pennant’s public mutation and purge surfaces remain callable outside the wrapper**  
   File: `config/pennant.php:15–27`; `modules/Platform/src/PlatformServiceProvider.php:74–82`

   The branch restricts references by a repository architecture scan, but Laravel Pennant still exposes its manager/store and package commands. The guard is not a runtime authorization boundary. `pennant:purge` can mutate the flag table, and any code that resolves `FeatureManager` can call `activate`, `deactivate`, or `purge` without the audit wrapper.

   Purging appears to fail closed by removing the row, but it still bypasses the required audited operator workflow and may make audit/state history misleading. Direct manager access can also enable the flag without an audit.

   Safe fix: disable or unregister mutation commands not part of the approved runbook, and encapsulate the Pennant manager behind a service that is not injected into application code. Add a test that the unsupported command is unavailable and that all supported state changes create the required audit event.

5. **Low — rollback is fail-safe for activation but loses flag-state history**  
   File: `modules/Platform/database/migrations/2026_11_15_000100_create_features_table.php:29–36`

   Dropping `features` turns flags off, which is the safe operational direction, but destroys the stored state and prevents restoring the prior flag state from the database. This is especially relevant because deployment rollback can remove a table containing an operator-controlled production decision.

   Safe fix: document the required pre-rollback backup/export of flag rows and restoration procedure, or use a reversible migration strategy that preserves the table/state while the application rollback is performed. Add a migration rollback test covering both behavior and recoverability.

Missing tests

- Concurrent `on`/`off` switches with assertions for final state and audit ordering/content.
- First-read initialization racing with `feature:switch`.
- Direct manager/store mutation attempts and `pennant:purge` behavior.
- Verification that unsupported Pennant mutation commands are disabled or cannot alter production flags.
- A database-account test demonstrating the intended write privileges for `features`, including whether the app account is intentionally trusted to bypass auditing.
- Audit failure after a concurrent or repeated switch, including rollback and cache state.
- Web-request cache behavior after an operator switch in a long-lived process.
- Worker behavior after a failed job and after a switch occurring while a job is running.
- Migration rollback with an existing enabled flag and documented state recovery.

Recommendation

**No.** The branch weakens the stated guarantee that feature changes occur only through an audited operator action: the mutable application grant and exposed Pennant mutation surfaces permit unaudited state changes. It also lacks serialization for concurrent switches, which can make the final state and audit trail diverge.

Unresolved assumptions / questions

- Is the application database account deliberately trusted to update `features`, or must the audited-command guarantee hold even against application-account misuse?
- Should concurrent switch commands be last-writer-wins, reject stale transitions, or serialize in command arrival order?
- Is `pennant:purge` allowed operationally, and if so, must it generate an audit event?
- Must enabled flag state survive an application rollback, or is “all flags off” the complete rollback contract?

What I did not check

- I did not run tests, migrations, Laravel commands, static analysis, or application code.
- `vendor/laravel/pennant` was not present in the working tree, so I could not inspect the installed Pennant 1.26.0 source directly; conclusions about its package surfaces are based on the branch configuration and documented Laravel Pennant behavior.
- I did not inspect unrelated modules or deployment infrastructure beyond the files relevant to this review.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "no" accepted: on `main` the running app could not change a flag at all, so a writable `features` table made the branch less safe than `main`. All five findings fixed in the next commit.

| # | Finding | Disposition |
|---|---|---|
| 1 | High: the app account could write `features` without an audit row | **Fixed.** `features` moved to grant class `read_only`: the app account can only read it, so no app code path, injection or worker can switch a flag. `Features::switch()` writes on the `migrator` connection, which only the migrator service has (`docker compose run --rm migrator php artisan feature:switch …`); the command and the service refuse without it. Tests: app UPDATE, INSERT, DELETE and Pennant `activate()` are denied (MySQL 1142). |
| 2 | High: concurrent switches were not serialized | **Fixed.** `switch()` locks the flag row (`SELECT … FOR UPDATE`) on the migrator connection, re-reads it, and writes the change and the audit row (`AuditLogger::on('migrator')`) in that transaction. Tests in parallel processes: four "on" at once give one change and one audit row; three rounds of mixed on/off requests leave audited states that alternate, and the last one is the stored state. |
| 3 | Medium: the first-read insert could race | **Fixed.** The migration stores each flag's global row (off), so the app never inserts one (it could not, read-only); `switch()` refuses a flag without a row. Test: every known flag has its stored row. |
| 4 | Medium: Pennant's own write and purge paths bypass the wrapper | **Fixed.** Pennant writes from app code fail at the database (see #1); `pennant:purge` is refused by Platform, also in the migrator service. The code rule keeping Pennant inside Platform stays as a tripwire. |
| 5 | Low: rollback lost the flag state | **Fixed.** The rollback refuses while any flag is on ("switch it off with feature:switch before rolling back"), so the audit trail and the state agree; with every flag off, dropping the table loses nothing. |

Questions answered: the app account is not trusted to change flags; switches are serialized by the row lock (each audited change is a real transition, the last committed one wins); `pennant:purge` is not allowed; the rollback contract is "only when every flag is off".

Not added: a web-request cache test for long-lived processes (php-fpm builds a new application per request; Pennant flushes its cache on Octane's request events) and a "switch while a job runs" test (the job keeps its start-of-job read, by design, as with grants).
