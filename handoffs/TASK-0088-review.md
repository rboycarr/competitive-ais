# TASK-0088 — Codex verification of the TASK-0087 fix (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1afd5-ff02-7f02-b0a5-b5bdc97bca54 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0088.prompt.md`, branch `feature/intelligence-submission` @ `9f39bf7` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `ed8cfa2bef0190e0276b43bf23f454f2eef810a0e0e145349c3ab3a6626347e5` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0087 finding #1

**Resolved for users who have `intelligence.view`.**

Evidence:

- `modules/Intelligence/routes/web.php:28` now uses `can:intelligence.view`, instead of `can:intelligence.submit`.
- `modules/Intelligence/src/Actions/IntelligenceWorkflowService.php:40-43` checks `ensureSeenBy()` before checking `intelligence.submit`.
- `modules/Intelligence/tests/Feature/IntelligenceSubmissionTest.php:141-154` verifies hidden items return 404 for users with and without submit permission.

## 2. New finding

**Medium — users without `intelligence.view` still receive 403 before hidden-item visibility is evaluated.**

File: `modules/Intelligence/routes/web.php:28`

Scenario: An authenticated user requests a hidden item’s submit endpoint but lacks `intelligence.view`. The route middleware rejects the request with 403 before `IntelligenceWorkflowService::submit()` can call `ensureSeenBy()` and return the documented 404.

The added test grants all permissions to both users, so it does not cover this case.

Safe fix: Remove the route-level `can:intelligence.view` middleware from the submit route and rely on the service’s visibility check first, followed by `intelligence.submit`. Add a test for a hidden item requested by an authenticated user lacking both permissions, asserting 404.

## 3. Recommendation

**Yes after fixes.**

The original finding is resolved for users passing the new route middleware, but the authorization-ordering issue remains for users without `intelligence.view`.

## 4. What I did not check

- I did not run PHPUnit, static analysis, formatting, or any application commands.
- I did not inspect `.env`, ignored credential files, or `.secrets/`.
- I did not perform live database or concurrency testing.
- I did not audit unrelated modules or the complete architecture documentation.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). TASK-0087 #1 resolved for users with `intelligence.view`.

| # | Finding | Disposition |
|---|---|---|
| 1 | Medium: a user without `intelligence.view` gets 403 on a hidden item's submit URL before visibility is evaluated | **Accepted for P1-42, hardened app-wide later (PO decision O40, 2026-11-06).** The same holds for every route that names a record in every module: Laravel's implicit binding (`SubstituteBindings`) resolves the ULID before the route's `can:` middleware, so for a user without the permission an existing record answers 403 and a missing one 404. Exploiting it needs a guessed 128-bit ULID, and every seeded role has `intelligence.view`. Changing only the submit route would make it differ from the item page and every other route. New story **P1-55** (Sprint 1.5): permissions checked before any record lookup, app-wide, with tests in every module. |
