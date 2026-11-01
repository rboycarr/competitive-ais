# TASK-0027 — Codex review of P1-16 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (10:28:11–10:29:25 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a196a7-0c5a-75c1-a07b-a24168726d55 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0027.prompt.md`, branch `feature/p1-16-audit-viewer` @ `3aca5d1` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (audit and schema: review mandatory) |
| Raw output SHA-256 | `ff461a835227dc4662f06675bdcc3b16de09107c2972468e2d4659d7cd855f08` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — cursor tampering can produce an unhandled server error**  
   File: [AuditLogController.php:49](/D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/src/Http/Controllers/Admin/AuditLogController.php:49)

   The `cursor` parameter is not validated or safely handled. A malformed or forged cursor can cause Laravel’s cursor paginator to throw an exception, resulting in a 500 response rather than a validation error or safe empty/first page.

   Scenario: an auditor requests `/admin/audit?cursor=forged`; the request reaches `cursorPaginate()` and may fail before the page is rendered.

   Fix: validate the cursor as a string and catch `InvalidCursorException`, returning a validation error or redirecting to the first page. Add a feature test for malformed and structurally valid-but-invalid cursors.

2. **Medium — actor selector exposes inactive-account identity data**  
   File: [AuditLogController.php:74-75](/D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/src/Http/Controllers/Admin/AuditLogController.php:74)

   The browser receives every user’s ULID and name, including invited, suspended, and deactivated accounts. The audit rows correctly apply `AccessScope`, but the filter metadata bypasses the intended inactive-account visibility boundary and unnecessarily discloses personal/account data.

   Scenario: an auditor with access to the log can enumerate names and ULIDs of suspended or deactivated users through the actor dropdown, even when those users do not appear as currently active actors.

   Fix: restrict the selector to active users, or provide only actors represented in visible audit rows. If historical inactive actors must remain searchable, return a minimal explicit historical-actor DTO and document that requirement.

3. **Low — the `next_page` audit context is omitted when false**  
   File: [AuditLogController.php:53-56](/D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/src/Http/Controllers/Admin/AuditLogController.php:53)

   `array_filter()` removes `next_page => false`, so first-page searches record only `filters`; later-page searches record `next_page => true`. This makes the registered audit context schema inconsistent and weakens the audit trail’s precise description of the search.

   Scenario: a first-page search produces no `next_page` field, while a cursor request produces it.

   Fix: preserve false values, e.g. pass `['filters' => ..., 'next_page' => $request->filled('cursor')]` directly. Add an assertion for both first-page and cursor searches.

## Missing tests

- Malformed, empty, and forged cursor values return a controlled validation response rather than 500.
- Actor selector excludes inactive accounts, or explicitly verifies the approved historical-actor behavior.
- `audit.searched` always contains `next_page: false` on the first page and `next_page: true` for cursor requests.
- Search results cannot be bypassed using combinations of filters and cursor values.
- Large-volume query plans for actor, action, subject, and time-range filters, including the new migration’s index effectiveness.
- Authorization test for an inactive `audit.view` holder; the route should remain denied by `AccessScope`/account status.
- Browser payload assertions ensuring no inactive-user metadata, IP data, row hashes, or unintended context reaches the client.

## Recommendation

**Yes after fixes.**

The branch preserves the append-only model and applies `visibleTo()` to audit rows. The identified issues do not appear to permit forging or modifying audit events, but cursor error handling and actor metadata exposure should be corrected before merge.

## What I did not check

I did not run the backend or frontend test suites, static analysis, migrations, CI scripts, database `EXPLAIN`, or browser tests. I only inspected the requested documentation, branch diff, changed files, and read-only git output.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

Recommendation "yes after fixes".

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Forged cursor causes a 500 | Medium | **Accept** | Confirmed in laravel/framework v13.39.0: `Cursor::fromEncoded()` returns null for undecodable input (first page), but a cursor that decodes without an `id` makes `Cursor::parameter()` throw. `AuditSearchRequest` now requires a cursor to decode to exactly an integer `id` and `_pointsToNextItems`, at most 200 characters; anything else is a validation error ("This page link is not valid"). Test: five forged variants are refused and no search is recorded; the genuine next-page link still works. |
| 2 | Actor selector lists inactive accounts | Medium | **Accept in part** | The list keeps inactive accounts on purpose: past actions of suspended or deactivated users must stay searchable. It is now sent only to viewers who also hold `user.view` (the user directory shows the same names; all seeded `audit.view` roles hold it), so a custom role with `audit.view` alone gets no account list. Test: such a role receives an empty list; an auditor receives every account, including a deactivated one. |
| 3 | `next_page` omitted when false | Low | **Accept** | Both keys are always recorded. Tests: first page `next_page: false`, cursor page `true`. |
| Missing tests | — | — | **Accept in part** | Added: forged cursors, `next_page` on both pages, actor list per permission, an inactive `audit.view` holder is signed out. Filter and cursor combinations cannot bypass `visibleTo` (the scope is applied before filters and pagination; the matrix test runs the page path). Query plans on a large table: deferred to the Sprint 1.5 performance work (indexes exist for every filter: actor, action, subject ULID, time). Browser payload: IP, `row_hash` and `ip_address` absence already asserted. |
