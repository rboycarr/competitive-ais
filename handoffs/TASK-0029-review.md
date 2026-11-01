# TASK-0029 — Codex review of P1-17 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (12:08:18–12:09:45 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19702-b7a1-7cc2-a8a9-16cff5c9ccbc |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0029.prompt.md`, branch `feature/p1-17-sessions` @ `90cefb4` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authentication and sessions: review mandatory) |
| Raw output SHA-256 | `959a7a0ce2539dbafedb004e257440f6c4e14ad4de696d382a56f0a8975b0853` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — driver documentation is inconsistent with the implementation**

   **Files:** `docs/07-security-threat-model.md:77`; `docs/01-system-architecture.md:21`

   The branch changes the session driver to MySQL, but the security and architecture documentation still states that sessions are Redis-backed. This can cause deployment, capacity, backup, and incident-response procedures to use the wrong storage system.

   **Scenario:** An operator follows the threat model or architecture document and provisions/monitors Redis for session state, while the application uses MySQL. Session availability, cleanup, database grants, and sensitive-session-data handling are then mismanaged.

   **Fix:** Update all operational/security documentation to state that sessions use the MySQL `sessions` table, including required grants, retention/cleanup, performance considerations, and backup implications.

2. **Low — no concurrency regression test for revocation**

   **File:** `modules/Identity/tests/Feature/SessionsTest.php:55-79`

   The implementation deletes session rows and relies on Laravel’s database session handler not recreating a row when a concurrent request writes after revocation. The existing tests only verify sequential deletion.

   **Scenario:** A request from another device has already loaded its session while the owner revokes it. That request completes after revocation and writes session state. If the framework handler inserts or upserts missing rows, the revoked session becomes usable again.

   **Fix:** Add a two-connection/concurrent-request test using the actual Laravel database session handler: load a secondary session, revoke it, perform the delayed write, then assert the row is absent and the old session cannot authenticate. Pin the expected Laravel handler behavior or add an application-side revocation/version check if the test exposes resurrection.

## Missing tests

- Concurrent request write after single-session revocation.
- Concurrent request writes while “sign out all other devices” runs.
- A revoked remember-me cookie cannot authenticate after either revocation operation.
- A current-session handle cannot revoke the current session, including a forged valid HMAC handle.
- A valid handle for another user returns 404 and produces no audit event.
- Invalid-length, uppercase, malformed, and raw session-ID handles are rejected without database mutation.
- CSRF failure on both session-revocation endpoints.
- Database-account/grant verification that the configured app account has the required `sessions` privileges and no unexpected privileges.
- Session payload handling with representative Laravel session data, confirming no PHP object serialization is introduced and sensitive values are not exposed through the UI or logs.
- Stale/expired session rows are handled consistently in listing and revocation.
- Audit rollback behavior: if revocation fails, neither session deletion nor `session.revoked` is committed.

## Recommendation

**Yes after fixes.**

The branch correctly keeps raw session IDs out of the page response and audit context, scopes queries by the authenticated user, excludes the current session, uses keyed HMAC handles with constant-time comparison, requires recent password confirmation, cycles the remember token, and audits revocations.

The documentation mismatch should be corrected before merge, and the concurrent-write behavior should be covered by a test because it is directly identified in the review focus.

## What I did not check

- I did not run Pest, PHPUnit, frontend tests, CI, migrations, static analysis, or any application command, per the read-only review instructions.
- The installed `vendor` tree was unavailable, so I could not inspect the exact Laravel 13 database session-handler implementation. Therefore, the concurrency issue is reported as a missing regression test rather than a confirmed vulnerability.
- I did not inspect deployment infrastructure outside the changed files and directly referenced documentation.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

Recommendation "yes after fixes".

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Docs still say sessions are in Redis | Medium | **Accept** | `docs/07` controls table and `docs/01` component list now say MySQL; `docs/02` gains the operations notes: grants (`mutable` class), cleanup (session lottery 2/100 after 120 min), backups (empty `sessions` after a restore), load. |
| 2 | No test that a concurrent write cannot resurrect a revoked session | Low | **Accept (behaviour confirmed safe)** | Laravel's `DatabaseSessionHandler` (v13.39.0): `read()` sets `exists = true` when the row is found, and `write()` then calls `performUpdate()` (an UPDATE by ID, no insert fallback), so a request that loaded the session before the revocation updates nothing. A request that starts after the revocation finds no row and gets a new, unauthenticated session. Test with the real handler: read, revoke, late write: the row stays gone. |
| Missing tests | — | — | **Accept in part** | Added: the revoked device's remember cookie signs in before and not after revocation; malformed handles (upper case, short, long, non-hex) change nothing and record nothing. Already covered: another user's valid handle and the current session's handle are 404 without audit; a raw session ID is not a handle. Grants: `DatabaseAccountsTest` checks every table, `sessions` included, against the policy. Not added: CSRF (framework middleware, disabled by Laravel during tests), payload content (framework format, never sent to the page), audit rollback (revocation and audit run in one transaction, as for every audited action). |
