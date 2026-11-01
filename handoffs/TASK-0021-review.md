# TASK-0021 — Codex review of P1-12 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (03:48:03–03:49:11 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19538-b5da-7480-9d92-a4c0559a8866 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0021.prompt.md`, branch `feature/p1-12-enforce-mfa` @ `fe01a2e` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (auth and MFA: review mandatory) |
| Raw output SHA-256 | `7fe4d5b2800ef87320985f8aefb8950e7f8b03f02320e1bda059aed11c228c45` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — privileged direct permissions bypass MFA**

   **File:** `modules/Identity/src/Models/User.php:47-53`

   `hasPermissionTo()` is gated, but Spatie’s inherited `hasDirectPermission()` remains ungated. A user with a privileged permission assigned directly can satisfy authorization code using `hasDirectPermission('user.manage')` despite lacking confirmed MFA. The same risk applies to any future code using raw permission collections or equivalent direct-permission helpers.

   **Safe fix:** Override or prohibit direct-permission checks for names in `MfaRequirement::PRIVILEGED_PERMISSIONS`, and add an authorization-facing helper that always applies the MFA condition. Ensure route/policy code cannot use ungated raw helpers.

2. **Medium — middleware coverage is not explicitly guaranteed for API and non-`web` routes**

   **File:** `modules/Identity/src/IdentityServiceProvider.php:133-138`

   Enforcement is appended only to the `web` middleware group. A privileged user could access a privileged endpoint registered under `api`, another middleware group, or a route without `web`; the middleware would not run. The permission override protects calls through `hasPermissionTo()`, but this branch’s stated “only enrollment and sign-out” guarantee is broader than that single mechanism.

   **Safe fix:** Apply the enforcement middleware globally after authentication resolution, or explicitly attach it to every authenticated route group, including API/Sanctum routes. Keep server-side permission checks on every privileged action.

3. **Low — enrollment path matching is maintained as a hard-coded pathname allowlist**

   **File:** `modules/Identity/src/MfaRequirement.php:25-33`

   New Fortify enrollment or password-confirmation routes can be unintentionally blocked, while a future route could accidentally be added under an allowed path. This is operationally fragile rather than an observed bypass in the current diff.

   **Safe fix:** Use named routes or explicit route metadata for enrollment/sign-out exceptions, and test every registered Fortify route.

## Missing tests

- Direct assignment of each privileged permission, followed by `hasDirectPermission()`, any direct-permission helper, policy checks, and route middleware authorization.
- Privileged users calling every protected API/Sanctum route without MFA.
- Requests to `/user/security-anything`, encoded slashes, query-string variants, and alternate HTTP methods.
- Pending secret without `two_factor_confirmed_at`.
- Disabling MFA after previously confirming it, followed by privileged authorization attempts.
- Every allowed enrollment and password-confirmation endpoint, both HTML and JSON.
- `Gate::before`, wildcard permission, and super-admin behavior if enabled later.
- Shared Inertia props for enrolled, pending, and unenrolled users, confirming no secret or recovery-code exposure.

## Recommendation

**No.** The public direct-permission path is not subject to the MFA guarantee, and enforcement is limited to the `web` group. Fix these issues and add the authorization-path tests before merging.

## What I did not check

I did not execute tests, CI jobs, migrations, application requests, or runtime route inspection, per the read-only review constraints. I also did not validate behavior against a live MySQL/Redis/queue environment.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding (short) | Severity | Decision | Fix |
|---|---|---|---|---|
| 1 | `hasDirectPermission()` bypasses the two-factor rule | High | **Accept** | `User::hasDirectPermission()` applies the same rule (`mfaAllows`). `hasAnyPermission`, `hasAllPermissions` and `checkPermissionTo` already go through `hasPermissionTo`. The raw collections (`getAllPermissions`, `getDirectPermissions`) list what is assigned and are documented as not for authorization. Test: a privileged permission given directly to a user is refused by `hasDirectPermission`, `hasPermissionTo`, `hasAnyPermission` and `can` until two-factor is on. |
| 2 | Enforcement only in the `web` group | Medium | **Accept** | `EnsureMfaEnrolled` is appended to the `api` group as well (no API routes exist yet). Test: an `/api/v1` route answers 403 problem+json for an unenrolled super admin and 200 after enrollment. |
| 3 | Path allowlist is fragile | Low | **Accept** | The allowlist is by route name (`MfaRequirement::ENROLLMENT_ROUTES`). Tests: every listed name is registered; `/user/security-anything` is blocked; a query string on the security page does not change the outcome. |
| Missing tests | — | — | **Accept in part** | Added: direct permissions, pending (unconfirmed) secret, turning two-factor off withdraws the permissions, API group, look-alike paths, route names. Already covered: security page and shared props hold no secrets (LoginTest), enrollment endpoints reachable. `Gate::before`, wildcard permissions and super-user shortcuts are not used; adding any of them is a reviewed change. |
