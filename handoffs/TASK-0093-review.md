# TASK-0093 — Codex verification of the TASK-0092 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b2e8-3dd9-73d2-84e4-457522b55257 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0093.prompt.md`, branch `feature/provenance-view` @ `f9c8b9a` (fixes in `f5f450a`; clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `9f209cd47df1c1ede0081dc1391e9d06afaf43d25e634589b9c3687ec98c4796` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0092 findings

1. **High — audit-context leakage:** **Resolved.**  
   `ProvenanceController::redacted()` replaces all non-visible `*_ulid` values with `hidden`. The visible set includes the item, claims, reviews, visible evidence, and authorized linked items. The added test covers hidden evidence.

2. **Medium — source lookup N+1:** **Resolved.**  
   Results are memoized by source ID and policy ID in `$recorded`, so repeated citations reuse the same lookup.

3. **Medium — linked-item repeated queries:** **Resolved.**  
   Replaced, superseding, and replacement items are collected and resolved through one authorized `seenBy()` query.

4. **Low — audit pagination/cap:** **Resolved as accepted/documented.**  
   The controller explicitly documents the first 200 events as a bounded snapshot and exposes `more`; the audit log remains the complete paginated view. Tests cover 200 versus 201 events.

## 2. New findings

No new security or engineering findings identified.

All audit actions defined by the service providers that can apply to an intelligence item use flat, allowlisted context fields. Their identifier fields are covered:

- claims/evidence: `claim_ulid`, `evidence_ulid`
- reviews: `review_ulid`, `reviewer_ulid`
- publication: `approval_ulid`, `review_ulid`
- linked items: `replacement_ulid`, `replaces_ulid`

The redactor’s top-level handling is sufficient for the currently defined audit schemas; no nested identifier-bearing context schema was found.

## 3. Answers to the brief

1. **Is each finding resolved?**  
   Yes. Findings 1–3 are fixed in code; finding 4 is explicitly documented and tested as an accepted bounded snapshot.

2. **Can another context key carry a hidden identifier?**  
   Not among the currently defined audit actions applicable to intelligence items. All relevant `*_ulid` fields are either included in the visible set or redacted. Non-ULID fields are codes, counts, booleans, versions, or code arrays.

3. **Is it right to keep `reviewer_ulid`?**  
   Yes under the current design: it identifies a user, not a hidden evidence/intelligence record, and reviewer identity is intentionally displayed with review metadata. If privacy minimization becomes stricter later, it could be redacted independently, but retaining it is not a provenance leakage defect.

## 4. Recommendation

**Yes — safe to merge into main.**

I did not execute PHPUnit, static analysis, frontend tests, migrations, or browser tests. I only inspected the requested files and ran read-only Git/file inspection commands.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log). All four TASK-0092 findings resolved (#4 as documented and tested); no new finding; recommendation "yes". Nothing to change.
