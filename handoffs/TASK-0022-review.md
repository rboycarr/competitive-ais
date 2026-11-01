# TASK-0022 — Codex verification of the TASK-0021 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (03:59:03–03:59:37 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19542-c80a-7562-8136-25f693d25066 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0022.prompt.md`, branch `feature/p1-12-enforce-mfa` @ `ec7ee05` |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `2d0fe16833ce860854c9d073702bb53cc5d1355e3c1468eba96f6c0666cbcdd0` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0021 findings 1–3

1. **Complete** — `User::hasDirectPermission()` now applies `mfaAllows()` at `modules/Identity/src/Models/User.php:64–66`. The test covers direct assignment and authorization paths at `MfaEnforcementTest.php:94–109`.

2. **Partial** — API-group coverage was added at `IdentityServiceProvider.php:138–140`, and tested at `MfaEnforcementTest.php:127–136`. However, enforcement still depends on routes belonging to `web` or `api`; routes outside those groups remain uncovered. The original broader guarantee is therefore not fully established.

3. **Complete, with a residual design risk** — enforcement now uses named routes at `MfaRequirement.php:25–38`, and the test verifies registration and look-alike paths at `MfaEnforcementTest.php:138–149`.

## 2. New problems introduced

### Medium — Any route reusing an allowlisted name bypasses MFA enforcement

**File:** `modules/Identity/src/Http/Middleware/EnsureMfaEnrolled.php:25`

The middleware permits any request whose route name appears in `ENROLLMENT_ROUTES`. If a future privileged endpoint is accidentally assigned a name such as `logout`, `password.confirm`, or `security.show`, an unenrolled privileged user can access it without MFA.

**Failure scenario:** A privileged controller route is registered with `->name('logout')`; the middleware sees the allowlisted name and calls `$next($request)`.

**Suggested fix:** Use explicit route metadata or a dedicated middleware exemption attribute applied only to the intended Fortify routes. Add a regression test proving that an unrelated route with an allowlisted name remains blocked.

### Low — Direct-permission override drops the optional guard argument

**File:** `modules/Identity/src/Models/User.php:64–66`

The inherited Spatie method supports a guard argument, but the override accepts only `$permission` and calls `mfaAllows()` without a guard. Authorization using a non-default guard may resolve the wrong permission or fail inconsistently.

**Failure scenario:** Code calls `hasDirectPermission('user.manage', 'api')`; the override cannot preserve the requested guard context.

**Suggested fix:** Preserve the parent-compatible signature and pass `$guardName` through to both the delegated permission check and `mfaAllows()`.

## 3. Recommendation

**Yes after fixes.**

The direct-permission fix is effective, and API coverage is improved. The allowlisted route-name exemption should be constrained before merge because an unrelated privileged route can accidentally inherit an enrollment exemption. The non-default guard signature should also be corrected.

## 4. What I did not check

I did not execute tests, inspect runtime route registration, run Laravel bootstrapping, inspect middleware ordering in a live application, run migrations, or validate behavior against MySQL, Redis, Sanctum, or queue services. I only ran read-only file inspection and git commands, including `git diff HEAD^ HEAD --check`; I did not claim test execution.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes".

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| #2 partial | Routes outside `web`/`api` stay uncovered | — | **Accept** | Made a checked invariant: `MfaEnforcementTest::test_every_application_route_runs_the_enforcement` fails if any route except the `up` health check lacks `EnsureMfaEnrolled` in its resolved middleware. Horizon's group expands to `web` (covered). The local-disk `storage/{path}` route was the only gap; it is switched off (`config/filesystems.php`, `'serve' => false`): documents live in object storage. |
| Medium | A route reusing an allowlisted name is exempt | Medium | **Accept** | The exemption now needs the route name **and** its URI (`MfaRequirement::ENROLLMENT_ROUTES` is name ⇒ URI). Tests: routes named `logout` or with a borrowed name on another path are still redirected; every listed name is registered with that URI. |
| Low | `hasDirectPermission` override drops a guard argument | Low | **Reject (evidence)** | spatie/laravel-permission 8.3.0 declares `public function hasDirectPermission($permission): bool` (`vendor/spatie/laravel-permission/src/Traits/HasPermissions.php:317`), with no guard parameter; the override has the same signature. |
