# TASK-0023 — Codex review of P1-13 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (05:18:59–05:20:03 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1958b-f863-7da2-91cf-dafcdfae3014 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0023.prompt.md`, branch `feature/p1-13-roles-teams` @ `1206609` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (permissions and schema: review mandatory) |
| Raw output SHA-256 | `ef31079fca63b9ab10adfbbf73168a38694512041457a7ebbdfdfdea5d0beab1` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — lockout protection does not cover suspension/deactivation**

   **File:** `modules/Identity/src/Actions/RoleAssignmentService.php:58–61, 89`

   The last-active-super-admin invariant is enforced only when removing the `super_admin` role. It does not protect against the last active super admin being suspended or deactivated through another identity workflow or direct administrative action.

   **Failure scenario:** Two active super admins exist. The only remaining active super admin is suspended. The database now has zero active super admins, violating the stated lockout guarantee.

   **Safe fix:** Centralize account-status changes in an audited service and reject suspension/deactivation when it would leave zero active super admins. Lock the super-admin rows in the same transaction and add concurrent status-change tests.

2. **Medium — concurrent role changes can deadlock**

   **File:** `modules/Identity/src/Actions/RoleAssignmentService.php:47–50, 89`

   Each transaction locks the target user first, then `activeSuperAdmins()` locks all active super-admin rows. Two super admins demoting each other can acquire these locks in opposite order.

   **Failure scenario:** Request A locks user B, request B locks user A; both then count/lock the super-admin set. MySQL may deadlock and abort one request, causing an availability failure during emergency administration.

   **Safe fix:** Acquire locks in a deterministic order—lock the complete relevant super-admin set first, ordered by user ID—or use a dedicated singleton lock row. Add a real concurrent transaction test.

3. **Low — administration index pages do not independently enforce active-account status**

   **Files:** `modules/Identity/src/Http/Controllers/Admin/UserController.php:27–54`, `RoleController.php:23–38`, `TeamController.php:21–38`

   The index actions rely on route `can` middleware and do not explicitly reject suspended/deactivated users. The mutation services check `status === active`, but the read actions do not.

   **Failure scenario:** A user whose session remains valid after suspension can still access an admin listing if their permission cache/session state permits the route, receiving user emails, roles, team membership, and MFA-status metadata.

   **Safe fix:** Apply a shared active-user authorization middleware/policy to all administration routes, or make the permission gate require an active account. Add suspended-session tests for every admin page.

4. **Low — unbounded user/team listings create avoidable exposure and load**

   **Files:** `modules/Identity/src/Http/Controllers/Admin/UserController.php:31–44`, `TeamController.php:22–34`

   Admin pages load every user, invitation, team, and team member in one response. This exposes all user email addresses to every `user.view` holder and scales linearly with account/team volume.

   **Failure scenario:** A large tenant causes oversized Inertia responses or slow page rendering; a read-only user administrator can export the complete directory simply by loading the page.

   **Safe fix:** Paginate users and invitations, return only fields required by the UI, and confirm whether email visibility is intended for `user.view` or should require `user.manage`.

## Missing tests

- Concurrent demotion/removal of super-admin roles, including deadlock-safe behavior.
- Concurrent suspension/deactivation of the last active super admin.
- Attempt to suspend/deactivate the final active super admin through every available status-change path.
- Suspended or deactivated users with existing sessions requesting `/admin/users`, `/admin/roles`, and `/admin/teams`.
- Authorization tests for direct service invocation without route middleware.
- Duplicate team/role creation under concurrent requests.
- Large-directory/pagination and response-field minimization tests.
- Team lead removal/deactivation behavior, including whether a lead must remain a team member.

## Recommendation

**Yes after fixes.**

The branch has strong basic server-side checks, MFA enforcement, self-role-change prevention, role-permission ceilings, audit calls, and ULID route binding. The lockout guarantee should be completed for account suspension/deactivation, and concurrency handling should be hardened before merge.

## What I did not check

- I did not execute tests, migrations, static analysis, builds, or CI commands.
- I did not inspect unrelated changes outside the branch diff.
- I did not verify runtime database locking behavior against MySQL.
- I did not perform browser or network testing.
- I did not validate the claimed CI results in `TASK-0023.md`; they are treated as implementer-provided evidence only.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes".

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Suspension could leave no active super admin | Medium | **Accept, deferred** | No suspension or deactivation workflow exists yet (statuses change only in the database). Recorded as a binding rule in `docs/02` §3.2: the story that adds status changes must refuse leaving zero active super admins, under the same lock order. |
| 2 | Concurrent demotions can deadlock | Medium | **Accept** | `RoleAssignmentService` locks every active super admin row by ID first, then the target, in every change: concurrent changes wait instead of deadlocking, and the count is stable after the locks. A real two-connection concurrency test is deferred to the Sprint 1.5 load tests. |
| 3 | Suspended sessions can still read admin pages | Low | **Accept** | Inactive accounts hold no permission at all (`User::hasPermissionTo`, `hasDirectPermission`), and `EnsureActiveAccount` (web and api groups, before the two-factor check) signs out an inactive account at its next request: pages redirect to login, JSON gets 401 problem+json. Test: an open session of a suspended super admin is ended on every admin page and the home page. |
| 4 | Unbounded listings | Low | **Defer** | Phase 1 has tens of users and teams; pagination comes when the lists grow (noted in `docs/02` §3.2). Emails in the user list are intended for `user.view` holders (directors and auditors use it as the directory). |
| Missing tests | — | — | **Accept in part** | Added: suspended sessions on every admin page. Direct service calls without middleware are covered by the services' own checks (each throws for actors without the permission). Duplicate names under concurrency are stopped by the UNIQUE indexes on `teams.name` and `roles(name, guard_name)`. |
