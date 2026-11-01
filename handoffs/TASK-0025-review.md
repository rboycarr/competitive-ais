# TASK-0025 — Codex review of P1-14 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (08:45:06–08:46:00 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19648-ad52-75a2-98fc-7194c06c2751 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0025.prompt.md`, branch `feature/p1-14-access-grants` @ `7e2d44b` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (grants and schema: review mandatory) |
| Raw output SHA-256 | `32704c43c561b7f80755dd74310d19b0abf1b337b2b8dc1bb4b31560538c7bd5` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No confirmed security or integrity findings in the reviewed diff. The implementation addresses the stated P1-14 guarantees:

- Self-grants are blocked for users and teams.
- Team grant/member races use the same team-row lock.
- Grants cannot exceed the actor’s clearance.
- `can_export` requires `export.run`.
- Actors must be active and authorized.
- Revocation is locked, one-way, audited, and has no delete route.
- `access_grants` uses the `no_delete` database grant class.
- Audit context is allowlisted and excludes the free-text reason.
- Browser output is rendered through Vue interpolation, not raw HTML.

## Missing tests

- Two-process/concurrent test for:
  - grant-to-team versus actor joining the team;
  - actor joining a team versus grant creation;
  - concurrent repeated revocation.
- Direct service tests proving inactive actors are rejected even when called outside HTTP middleware.
- Direct `AccessGrant::heldBy()` / future `AccessScope` tests proving suspended or deactivated users cannot receive effective access through an existing grant.
- Database privilege tests attempting `UPDATE` after `revoked_at` is set, confirming the application cannot alter revoked grants.
- Expiry boundary tests at the exact `expires_at` instant and with non-UTC input offsets.
- Audit rollback tests proving a failed grant or revoke leaves no audit row and no partial grant mutation.
- Tests for unauthorized `grant.manage` users with and without MFA, including direct service invocation.

## Recommendation

Yes, safe to merge into `main`, subject to the missing concurrency and downstream `AccessScope` tests being added before P1-15 consumes these grants.

## Unresolved assumptions / questions

- The branch intentionally omits the `AccessGrantCreated` and `AccessGrantRevoked` domain events specified by the Identity module contract. Confirm that deferring them until P1-15 is approved.
- Confirm that future `AccessScope` implementation will explicitly deny suspended/deactivated users even if their grant remains active.
- Confirm that the unpaginated grant list is acceptable for the expected Phase 1 volume.

## What I did not check

- I did not execute Pest, Vitest, static analysis, migrations, CI, or database-account tests, per the read-only review instructions.
- I did not inspect unrelated files outside the changed-file diff.
- I did not verify the claimed CI results in the brief.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

No findings; recommendation "yes" (with the concurrency and `AccessScope` tests before P1-15 relies on the grants).

| Item | Decision | Fix / rationale |
|---|---|---|
| Two-process concurrency (team grant vs. joining; repeated revocation) | **Defer** | Same treatment as TASK-0023 #2: the ordering comes from row locks (team row for grant and join, grant row for revoke), and a real two-connection test belongs with the Sprint 1.5 concurrency tests (P1-25 sets up parallel-request tests for the registry). |
| Direct service calls by inactive or unauthorized actors | **Accept** | Test added: an unenrolled director, a suspended director and an analyst are refused by `AccessGrantService::grant()` without any route, and a suspended director cannot revoke. |
| Suspended users and existing grants (`heldBy`, `AccessScope`) | **Accept, binding for P1-15** | Recorded in `docs/02` (P1-14 note): `AccessScope` must deny inactive accounts regardless of their grants. Today they hold no permission and their sessions end (`EnsureActiveAccount`). |
| UPDATE after revocation | **Accept the residual risk** | The app account needs UPDATE to revoke, so the database cannot stop a later UPDATE without a trigger (the migrator holds no TRIGGER privilege, by design). Only `revoke()` writes to a grant, it refuses already revoked grants, and every revocation is in the hashed audit trail. |
| Expiry at the exact instant; non-UTC input | **Accept** | Test added: an expiry sent with +08:00 is stored as the same UTC instant; the grant is active one second before and expired at the instant (`active()` and `status()` agree). |
| Audit rollback on failure | **Covered** | Audit records are written inside the same transaction as the change (`AuditLogger` inside `$db->transaction`), so a failure rolls back both; the refusal tests also assert that no grant row exists afterwards. |
| MFA for `grant.manage` | **Covered** | The unenrolled director in the new test; route-level enrollment is covered for every privileged permission in `MfaEnforcementTest`. |
| Domain events `AccessGrantCreated`/`AccessGrantRevoked` deferred | **PO to confirm** | No consumer before P1-15 (cache invalidation) and Phase 2 search; P1-13 also publishes none. Proposed: add them with P1-15. |
| Unpaginated grant list | **Defer** | Tens of grants in Phase 1, as for the user and team lists (`docs/02`). |
