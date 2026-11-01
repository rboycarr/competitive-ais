# TASK-0024 — Codex verification of the TASK-0023 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (raw output saved 05:31:25 UTC; earlier records in this series carry 2026-11-02, one day ahead of the machine clock, see the disposition) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19596-d3fb-7ac0-b09b-717a57c924a0 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0024.prompt.md`, branch `feature/p1-13-roles-teams` @ `9abdeec` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (permissions: review mandatory) |
| Raw output SHA-256 | `895b02a8f78235db36de0ad94f7ff689cc8ea0f2473a2d57f168f2d38218e633` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0023 disposition

- Finding 2 — **Complete.** `RoleAssignmentService.php:47–52` locks all active super-admin rows in deterministic `users.id` order before locking the target. This removes the prior opposite-order acquisition scenario. The deferred concurrent test remains unimplemented, so runtime MySQL behavior is unverified.

- Finding 3 — **Complete for the stated web/session guarantee.** `EnsureActiveAccount.php:21–30` detects inactive users, logs out, invalidates the session, and throws `AuthenticationException`. It is registered for both `web` and `api` groups at `IdentityServiceProvider.php:156–160`. Permission checks also deny inactive users in `User.php:54–62, 71–74`. Coverage was added at `AdministrationTest.php:180–196`.

- Finding 1 deferral — **Sound as scoped.** The disposition states no status-change workflow exists, and this commit does not introduce one. The future binding rule is documented in the handoff, so deferring enforcement is reasonable until status mutation exists.

- Finding 4 deferral — **Sound as an explicit product decision, but remains a known scalability risk.** No pagination or field reduction was added; the handoff records the Phase 1 volume assumption and intended email visibility.

## 2. New problems introduced

### Medium — API-token sessions are not actually revoked

**File:** `modules/Identity/src/Http/Middleware/EnsureActiveAccount.php:23–26`

The middleware always calls `Auth::guard('web')->logout()`. For an API request authenticated through another guard, such as Sanctum, this does not revoke the token or log out the active API guard. The request is rejected, but subsequent requests may continue presenting the token; any endpoint that relies only on authentication rather than permission checks can remain accessible.

**Fix:** Log out or invalidate the guard that authenticated the request (`$request->user()` / route guard), and define explicit token revocation behavior for suspended accounts. Add an API-authenticated suspended-user test covering both the current and subsequent requests.

### Low — middleware registration may duplicate middleware on repeated provider boot

**File:** `modules/Identity/src/IdentityServiceProvider.php:158–160`

The provider appends `EnsureActiveAccount` and `EnsureMfaEnrolled` every time `boot()` runs. In normal production boot this is generally once, but repeated application bootstrapping in long-lived workers or test setup can duplicate middleware, causing repeated logout/session handling and duplicated MFA processing.

**Fix:** Register these middleware through the application’s canonical middleware configuration, or guard registration against duplicates.

## 3. Recommendation

**Yes after fixes.**

Finding 2 is addressed and finding 3 is addressed for web sessions and permission-protected application paths. The API-guard/token issue makes the active-account guarantee incomplete for API authentication and should be fixed before merging.

## 4. What I did not check

- I did not execute tests, migrations, static analysis, builds, or CI.
- I did not verify MySQL lock behavior with real concurrent connections.
- I did not inspect or exercise every API route and authentication guard.
- I did not review unrelated branch changes outside the specified fix context.
- I did not perform browser, network, deployment, or production-database checks.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

Recommendation "yes after fixes". TASK-0023 #2 and #3 complete; deferrals of #1 and #4 sound.

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Only the `web` guard is logged out; API-token sessions not revoked | Medium | **Accept** | `EnsureActiveAccount` now checks every configured guard: a stateful guard is logged out, any other guard (API tokens, when Sanctum arrives) forgets the user, and the request is refused with 401 whichever guard authenticated it. Only the `web` session guard exists today. The check runs on every request, so a token of a suspended account is refused each time; revoking stored tokens on suspension is recorded for the story that adds status changes (with the TASK-0023 #1 rule). Test: an `api` route behind a token-style guard answers while the account is active and returns 401 problem+json after suspension, also for a web session on the same route. |
| 2 | Middleware may be appended twice on repeated boot | Low | **Reject, with evidence** | `Illuminate\Foundation\Http\Kernel::appendMiddlewareToGroup()` (laravel/framework v13.39.0, `Kernel.php:410`) appends only if `array_search($middleware, $this->middlewareGroups[$group]) === false`, so repeated calls cannot duplicate it. Noted in a comment in `IdentityServiceProvider::enforceMfaEnrollment()`; the test "every application route runs the enforcement" covers the registration. |
