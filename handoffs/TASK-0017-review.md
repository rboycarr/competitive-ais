# TASK-0017 — Codex review of P1-10 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (02:02:48–02:03:52 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a194d8-585f-74e3-b119-44bac832e252 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0017.prompt.md`, branch `feature/p1-10-invitations` @ `dd596e1` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (auth and schema: review mandatory) |
| Raw output SHA-256 | `0f609dc7ba336527fa30715a358c83354ff02630fff1a44428396907fdcd4397` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — inviter can grant unauthorized roles**  
   **File:** `modules/Identity/src/Actions/InvitationService.php:51–58`  
   **Problem:** The service limits clearance and only restricts `super_admin`, but does not require the inviter to hold an approved invitation-management permission or restrict other roles by authority. A low-privilege user passed as `$inviter` can invite a `ci_director`, gaining `grant.manage`, publishing, and other elevated permissions.  
   **Scenario:** A `ci_analyst` invokes any future invitation endpoint/service path with role `ci_director` and a permitted clearance. The new account receives director privileges.  
   **Fix:** Require an explicit invitation-management capability and enforce a role-assignment ceiling derived from the inviter’s effective permissions/role. Deny all role assignments exceeding that ceiling; add policy checks at every caller.

2. **Medium — seeded role matrix grants CI Director `user.view` contrary to docs**  
   **File:** `modules/Identity/database/migrations/2026_11_02_000300_seed_roles_and_permissions.php:25`  
   **Problem:** `docs/07-security-threat-model.md:52` specifies CI Director has no `user.view`; the migration grants it.  
   **Scenario:** A CI Director can access user-management views or endpoints guarded only by `user.view`, exposing account metadata beyond the documented matrix.  
   **Fix:** Remove `user.view` from `ci_director`, or update the approved matrix and add an explicit privacy rationale and authorization tests.

3. **Medium — malformed tokens do not receive the same response as other unavailable invitations**  
   **File:** `modules/Identity/routes/web.php:9–10`  
   **Problem:** The route constraint rejects malformed tokens before the controller, producing Laravel’s default 404 response. Unknown, expired, revoked, and used valid-format tokens produce the custom `InvitationUnavailable` Inertia response. This violates the brief’s same-response enumeration requirement.  
   **Scenario:** An observer distinguishes malformed links from revoked/expired links by response body, headers, or Inertia payload.  
   **Fix:** Remove the route constraint and let `findOpen()` handle validation, or install a matching custom 404 handler for these paths that always returns the same unavailable response.

4. **Low — Argon2id test cost is environment-overridable in production**  
   **File:** `config/hashing.php:24–28`  
   **Problem:** Production behavior depends directly on `ARGON_MEMORY` and `ARGON_TIME`; the brief says the reduced cost must be test-only. A deployment carrying test environment variables can silently weaken password hashing.  
   **Scenario:** `ARGON_MEMORY=1024` is present in a production container, causing newly created passwords to use the test cost.  
   **Fix:** Apply reduced values only when `app()->environment('testing')`; otherwise use fixed production-safe constants or reject low values at boot/config validation. Add a production-configuration test.

## Missing tests

- Authorization matrix for every role attempting to invite every role, including analysts, collectors, readers, auditors, and directors.
- Inviter permission denial when a user has a role but lacks the explicit invitation-management capability.
- CI Director’s exact role permissions, including asserting `user.view` is absent if the docs remain authoritative.
- Identical status, body shape, Inertia component, and security headers for malformed, unknown, used, revoked, and expired links.
- Production environment with `ARGON_MEMORY`/`ARGON_TIME` set to test values must retain or reject them rather than weaken hashing.
- Concurrent invitation creation for the same email, including two transactions racing to replace the open invitation.
- Mail-send failure and after-commit behavior, ensuring no token is written to logs, queue payloads, audit context, or exception pages.
- Rate-limit behavior across GET and POST, shared IPs, and distributed attempts.

## Recommendation

**No.** The branch breaks stated guarantees: inviter authority is not enforced, and unavailable invitation responses are not uniform for malformed tokens. It also diverges from the approved role matrix.

## What I did not check

I did not run tests, migrations, builds, static analysis, CI commands, network calls, or database queries. I reviewed the requested documentation, branch diff, and changed files using read-only commands only.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Inviter authority not enforced beyond clearance and super_admin | High | **Accept** | An inviting user must be active and hold `user.manage`; except for a super_admin, they cannot hand out a role whose permissions they do not all hold, and only a super_admin invites a super_admin; clearance stays capped by their own. Tests: all eight roles inviting all eight roles (only super_admin succeeds, since only it holds `user.manage` in the matrix); a custom role with `user.manage` and a few view permissions can invite a subset role but not `ci_analyst` or `super_admin`; a suspended inviter is refused; a super admin cannot exceed their own clearance. |
| 2 | Seed gives CI Director `user.view` "contrary to docs" | Medium | **Reject (evidence)** | `docs/07-security-threat-model.md` §3, row `user.view / user.manage`: CI Director is `✓ / –`, i.e. `user.view` yes, `user.manage` no. The migration matches. The seeding test already checks the director cannot `user.manage` by not holding it; no change. |
| 3 | Malformed tokens got Laravel's default 404, not the invitation page | Medium | **Accept** | The route pattern is removed; the service rejects malformed tokens, so unknown, malformed, upper-case and oversized tokens all return 404 with the `Identity/InvitationUnavailable` page (tested). |
| 4 | Argon2id cost overridable by environment variables | Low | **Accept** | `config/hashing.php` has fixed values again; `tests/TestCase.php` lowers the cost in code for tests only. Test: the config file contains no `env(` and yields Argon2id with memory 65536 and time 3. |
| Missing tests | — | — | **Accept in part** | Added: inviter matrix, permission ceiling, suspended inviter, uniform answers, fixed hashing config. Concurrent replacement of open invitations: the replacement and the insert run in one transaction; a race can at worst leave two open invitations for one address, and acceptance still creates one account (unique email + row lock). Mail failure: sending happens after commit, outside any queue or log; covered by design, tested once P1-13 adds the admin screen. |
