# TASK-0076 — Codex verification of the TASK-0075 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aa4b-dd63-7511-85a6-93b82ab83577 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0076.prompt.md`, branch `feature/evidence-scopes` @ `4364946` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `e5cfe36bc9d3b1ae67ba00b731028d74363b641fe771d8d42697d230e28a72ff` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0075 findings

All three findings are resolved.

1. **High — resolved.**  
   `EvidenceDownloadService` and `EvidenceHoldService` now re-run `EvidenceAsset::seenBy()` after acquiring the asset lock. A newly added inaccessible scope causes a not-found failure before issuing a download link or recording a hold.

2. **Medium — resolved.**  
   The shared authorization matrix now covers POST `/evidence/{ulid}/hold`, including the active, two-factor director users.

3. **Low — resolved.**  
   `EvidenceUploadService::scopes()` now rejects null, empty, and malformed entries instead of silently discarding them.

No TASK-0075 finding was correctly declined.

## 2. New findings

None identified. The fix commit does not introduce a merge-blocking regression.

The locking rule for future scope additions is documented in `docs/11-delivery-roadmap.md`: scope writers must lock the asset row first. That is sufficient when followed, because downloads acquire a shared lock and holds acquire an exclusive lock before rechecking visibility.

## 3. Recommendation

**Yes — safe to merge into `main`.**

The deterministic race test would fail against the pre-fix implementation: the transaction-beginning listener adds an inaccessible scope, while the old services would continue without a second visibility check.

## 4. What I did not check

- I did not run tests, static analysis, migrations, or CI.
- I did not inspect prohibited credential files.
- I did not perform a full review of unrelated documentation, ADRs, or all skills because this review was scoped to TASK-0076 and the changed files.
- Future P1-36b scope-insertion code does not yet exist; its compliance with the documented row-lock rule will need review when implemented.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

All three TASK-0075 findings were verified resolved, with no new findings and a recommendation of "yes". The one open note is that the P1-36b scope writer must follow the row-lock rule. That rule is in P1-36b's acceptance (`docs/11`) and will be checked in that story's review.
