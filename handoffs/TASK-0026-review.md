# TASK-0026 — Codex review of P1-15 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (09:42:12–09:43:14 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1967c-f8fd-7ae2-a84f-1e26e0beadfb |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0026.prompt.md`, branch `feature/p1-15-access-scope` @ `2f9a175` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization: review mandatory) |
| Raw output SHA-256 | `b07af3359d27e015d316ff61c700a58474548823b96cc633a455be7c6de51001` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No security or correctness findings identified in the reviewed diff.

The predicate in `GrantAccessScope` matches the documented rules for:

- clearance limits;
- restricted records requiring grants;
- scoped records requiring grants;
- active, non-expired, non-revoked grants;
- direct and team grants;
- inactive-account default deny;
- no Super Admin visibility bypass;
- 404 behavior through `ensureVisible()`;
- grouped SQL conditions in `applyTo()`.

Grant and team-membership mutations also clear the scoped per-request cache.

## Missing tests

- Cache isolation across separate queue-job/request lifecycles.
- Team membership removal followed by an authorization check in the same request.
- Grant expiry at the exact boundary timestamp.
- `visibleTo()` combined with an existing `where`/`orWhere` query to verify SQL grouping cannot widen results.
- A future typed competitor/market grant matrix proving all-of semantics when scoped grants are introduced.
- Direct tests that `forget()` invalidates cached grants after team membership changes.

## Recommendation

Yes — safe to merge into `main`, subject to adding the missing regression coverage before dependent visibility paths are implemented.

## Unresolved assumptions / questions

- The implementation intentionally treats every scoped record as covered by any qualifying `scope_type = all` grant because competitor and market grants do not yet exist. This should be revisited when the registry and typed grants are added.
- All visibility consumers must use `AccessScope`/`visibleTo()`; the current branch cannot prove that future modules will not bypass it.

## What I did not check

- I did not run Pest, CI, static analysis, migrations, or application commands.
- I did not inspect unchanged modules for existing visibility bypasses.
- I did not validate database query plans or behavior against MySQL 8.4; the included probe tests use SQLite.
- I did not inspect search, vector, download, export, or AI retrieval paths because they are not part of this branch’s diff.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

No findings; recommendation "yes". One of the missing tests exposed a real defect, fixed before merge.

| Item | Decision | Fix / rationale |
|---|---|---|
| `visibleTo()` combined with existing `where`/`orWhere` | **Accept — defect found and fixed** | Through the `visibleTo` scope Eloquent groups the conditions, but a direct `AccessScope::applyTo()` call on a query that already had `a OR b` produced `a OR b AND visible`, so a hidden record matching `a` was returned. `GrantAccessScope::applyTo()` now nests the existing conditions first. Test: both call styles with three OR conditions; run against the previous code it fails (a public-clearance user without grants got `public/competitor` and `restricted/unscoped`). |
| Team membership removal in the same request; `forget()` after team changes | **Accept** | Test: a member of a team holding a grant loses access in the same request after `TeamService::removeMember()`. |
| Grant expiry at the exact instant | **Accept** | Test: expired at the instant (`active()` uses `expires_at > now`); also covered in `AccessGrantTest`. |
| Cache isolation across requests and jobs | **Accept** | Test: a change made outside the grant and team actions stays cached for the current request (documented behaviour) and is seen once the scoped instances are reset, as Laravel does between queued jobs and Octane requests; PHP-FPM builds a new container per request. |
| Typed competitor/market grant matrix | **Defer to the registry story** | Typed grants do not exist yet (PO decision 2026-11-01); the registry story widens `GrantSet::covers()` and adds per-competitor and per-market variants to `AuthorizationMatrix::GRANTS`. |
| Scoped records covered by any `all` grant | **By design** | Same as above; recorded in `docs/03` and `docs/02`. |
| Future modules bypassing `AccessScope` | **Accept, enforced later** | Each module runs its read paths through `AuthorizationMatrix`; the route-inventory test (docs/09 item 19) arrives with the first module that shows records (Sprint 1.2). |
