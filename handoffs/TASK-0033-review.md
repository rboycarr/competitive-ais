# TASK-0033 — Codex review of P1-20a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (14:38:08–14:39:09 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1978b-e348-7c81-9f65-78994e9a2df9 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0033.prompt.md`, branch `feature/p1-20a-competitor-grants` @ `eb6ae3e` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `db0ed25f5c16554e94063607543cd342cb0b9f7d2a2e86a534317208a34ff837` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No security or correctness findings identified in the reviewed diff.

The predicate implementation and SQL agree on:

- all-of competitor coverage;
- restricted records requiring an explicit grant;
- market-scoped records remaining inaccessible through competitor grants;
- expiry, revocation, team membership, and inactive-user handling;
- grouped existing `OR` conditions;
- empty covered-competitor sets;
- typed competitor foreign keys and database CHECK enforcement.

Grant creation validates visibility through `CompetitorLookup`, and the grants page masks names for competitors outside the administrator’s visible set. Identity depends on Registry only through public DTO/lookup contracts.

## Missing tests

- Two separate competitor grants jointly covering a record scoped to competitors A and B.
- A hidden competitor grant appearing in the grants list and confirming that only the generic placeholder is rendered.
- Migration rollback with existing competitor grants, including confirmation that data remains unchanged and rollback fails safely.
- Direct `AccessGrantService::grant()` calls using competitor IDs/ULIDs, independent of the HTTP request validation.
- SQL authorization tests using a real MySQL schema and actual registry pivot tables, rather than only the SQLite probe model.
- Concurrent grant creation/revocation and team-membership changes involving competitor-scoped grants.

## Recommendation

Yes — safe to merge into `main`.

The missing tests are worthwhile hardening, but I found no issue that makes this branch less safe than `main` or violates a stated guarantee.

## What I did not check

- I did not run tests, static analysis, builds, migrations, or CI.
- I did not inspect the full repository history beyond the recent branch commits.
- I did not validate generated SQL against a live MySQL instance.
- I did not review unrelated files outside the requested guidance and branch changes.
- I did not verify the CI results claimed in `handoffs/TASK-0033.md`; those are treated as implementer-reported evidence only.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

No findings; recommendation "yes".

| Missing test | Decision | Rationale |
|---|---|---|
| Two competitor grants jointly covering a record about A and B | **Added** | `AccessScopeTest`: A at confidential + B at internal does not open a confidential A+B record (an internal one, yes); raising B to confidential opens it; a record about A and a market stays hidden. |
| Hidden competitor on the grants page | **Added** | `AccessGrantTest`: a grant on a competitor the admin cannot see shows "A competitor you cannot see", and the name is nowhere in the response. |
| Rollback with competitor grants | **Added** | `AccessGrantTest`: the migration's `down()` refuses before any DDL and the grant keeps its scope and competitor. |
| Direct service calls with competitor ULIDs | **Covered** | The HTTP tests run through `AccessGrantService::grant()`, which resolves the competitor itself through `CompetitorLookup` (visible-only); the request rules are only the first check. |
| SQL against real MySQL pivot tables | **Defer** | No registry table with competitor scope pivots exists yet; the first (`evidence_asset_competitor`, Sprint 1.3) runs the matrix on MySQL. The `competitors`, audit and competitor-list matrix runs already use MySQL. |
| Concurrent grant and membership changes | **Defer** | Sprint 1.5 concurrency tests, as for TASK-0023/0025. |
