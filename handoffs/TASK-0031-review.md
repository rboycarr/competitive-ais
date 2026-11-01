# TASK-0031 — Codex review of P1-20 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (13:37:29–13:38:26 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19754-5ce6-7111-ae97-8eeb427833cf |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0031.prompt.md`, branch `feature/p1-20-competitors` @ `176ad58` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (schema, grants, authorization: review mandatory) |
| Raw output SHA-256 | `cc7bb217f77c591ddbd988dd62e2a0368e36b8e3b78d2b8d26ae7d3000ff9633` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — slug suffix generation skips `-2`**

   **File:** `modules/Registry/src/Actions/CompetitorService.php:181–193`

   **Problem:** When the base slug already exists, `$attempt = 2` produces `$base-3`, so the first collision becomes `acme-foods-3` instead of `acme-foods-2`.

   **Scenario:** Register “Acme Foods” twice. The second record receives `acme-foods-3`; the included test expects `acme-foods-2`.

   **Fix:** Use `$suffix = $attempt` for attempts after the first, or maintain an explicit next-suffix counter. Add a concurrent collision test.

2. **Medium — analyst assignment can violate the “active holder” invariant under a race**

   **Files:** `modules/Registry/src/Actions/CompetitorService.php:56–71, 152–164`

   **Problem:** Assignment is validated through `activeHolders()` before the competitor write, but the selected user can be suspended, lose `competitor.manage`, or lose MFA eligibility before the transaction commits. The database only enforces the foreign key, not the active/permission requirement.

   **Scenario:** Request assigns analyst A; between directory lookup and insert/update, an administrator suspends A or revokes the permission. The competitor still stores A as assigned analyst.

   **Fix:** Revalidate assignment inside the write transaction while locking the user row, preferably through an Identity contract/action that validates the current active permission state. Add create/update race or equivalent transactional tests.

3. **Low — website validation is duplicated and inconsistent**

   **Files:** `modules/Registry/src/Http/Requests/CompetitorRequest.php:21–30`; `modules/Registry/src/Actions/CompetitorService.php:129–134`; migration `:41–42`

   **Problem:** The request only checks string length; the service and database use different URL rules. The service regex also rejects some valid minimal HTTP(S) hosts and accepts values without robust host/URL validation.

   **Scenario:** A value passes request validation but fails later in the service, or a database/API caller stores a malformed `http(s)://` value that the UI treats as a link.

   **Fix:** Use one shared URL validation rule/value object with an explicit `http`/`https` scheme and valid host, apply it at the request/service boundary, and keep the database check as defense in depth. Add malformed-host and minimal-valid-host tests.

## Missing tests

- First slug collision produces `name-2`; subsequent collisions produce `name-3`, etc.
- Concurrent registrations with the same name produce unique, gap-free suffix behavior where possible.
- Analyst becomes suspended, loses `competitor.manage`, or loses required MFA state between validation and commit.
- Direct service invocation with malformed website, country, tier, or classification values.
- Direct service invocation by a super admin without a content role.
- Classification downgrade/raise with concurrent updates and stale model instances.
- Database-level denial of `DELETE` and `TRUNCATE` for `competitors` under the configured `no_delete` grant.
- Migration round-trip specifically including the new foreign keys, checks, full-text index, and grant policy.
- HTML/attribute rendering checks confirming descriptions and names are rendered as text and only HTTP(S) websites become links.

## Recommendation

**No.**

The branch contains a concrete slug-generation defect that breaks the stated slug behavior and appears inconsistent with its own feature test. Fix that issue and address the analyst-assignment race before merge.

## What I did not check

- I did not execute Pest, frontend tests, static analysis, migrations, CI scripts, or database grant checks.
- I did not inspect the commit’s external CI run; only the repository’s stated evidence was available.
- I did not inspect unrelated pre-existing modules except where needed to trace `AccessScope`, permissions, and `UserDirectory`.
- I did not make any file changes or network calls.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

Recommendation "no", based on #1, which does not hold.

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | First slug collision becomes `-3` | Medium | **Reject, with evidence** | `slug()` returns the base on attempt 1 only when it is free; otherwise `$suffix = $attempt + 1`, which is 2 on attempt 1, so the first collision is `acme-foods-2`. Attempt 2 (a retry after a concurrent insert took the same slug) starts at 3. The feature test asserts `['acme-foods', 'acme-foods-2']` and passes. A comment now states this. |
| 2 | Analyst could be suspended or lose the permission between the check and the write | Medium | **Accept** | New `UserDirectory::lockedActiveHolder()` reads the user under a shared lock inside the competitor write transaction; role changes (`RoleAssignmentService`) lock the same row FOR UPDATE, so the two are ordered. Keeping the current analyst needs no check (a later suspension leaves the assignment until someone changes it, documented); a newly chosen analyst must qualify at that moment. Test: editing another field keeps a since-suspended analyst; choosing them anew is refused. |
| 3 | Website validated differently in request, service and database | Low | **Accept** | `CompetitorService::isWebAddress()` (`FILTER_VALIDATE_URL`, scheme http or https, non-empty host) is used by the request and the service; the database CHECK (`^https?://`) stays as defence in depth. Test: no host, spaces, triple slash, `mailto:` and scheme-less values refused in both; a URL with path and query accepted. |
| Missing tests | — | — | **Accept in part** | Added: service refuses a super admin without `competitor.manage`; website cases above; analyst kept vs. newly chosen. Already covered: DELETE/TRUNCATE denial for every `no_delete` table (`DatabaseAccountsTest`, data-driven, now includes `competitors`); migration round-trip in CI; descriptions rendered as text and only http(s) links (`Show.test.ts`). Deferred to Sprint 1.5 / P1-25: true concurrency tests (parallel registrations, stale-instance updates). |
