# TASK-0075 — Codex review of P1-36a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aa2e-56f1-7392-abb1-cc3388517399 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0075.prompt.md`, branch `feature/evidence-scopes` @ `31eaf3f` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `3651b13aec92a1a2b1a36647ddcb145aa6a9d909f86a94f58f64fb7d808f3750` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — merge blocker**  
   **Files:** `modules/Evidence/src/Actions/EvidenceDownloadService.php:45-51`; `modules/Evidence/src/Actions/EvidenceHoldService.php:51-58`

   Authorization is checked before the transaction, but the locked/current asset is not re-authorized after reload. A scope can be appended between the initial `seenBy()` check and `find()`/`findOrFail()`. The operation can then download or place a hold on an asset whose newly added scope the actor cannot access.

   **Safe fix:** Re-run `EvidenceAsset::seenBy(...)->whereKey($current->id)->exists()` inside the transaction after acquiring the lock, or lock and authorize the same current model before performing the action. Add a concurrent scope-addition test.

2. **Medium — not a merge blocker**  
   **File:** `modules/Evidence/tests/Feature/EvidenceScopeTest.php:42-80`

   The shared matrix covers `visibleTo()`, list, detail, and download, but legal-hold coverage is not matrix-wide. Only one director/no-grant case is tested.

   **Safe fix:** Add hold assertions for every relevant matrix row, including partial competitor grants, partial market grants, both scope types, unscoped assets, classification boundaries, and inactive users.

3. **Low — not a merge blocker**  
   **File:** `modules/Evidence/src/Actions/EvidenceUploadService.php:212-233`

   The service silently skips `null` and empty scope values. The HTTP request rejects these, but direct callers can submit malformed scope arrays and receive a successful upload with the malformed entries discarded.

   **Safe fix:** Reject null/empty entries in `scopes()` rather than silently ignoring them, keeping direct service use consistent with the request contract.

## P1-36a acceptance

Mostly met:

- Declared competitor and market scopes are accepted at upload.
- Competitor visibility is checked through `CompetitorLookup::findVisible`.
- Market existence is checked through `MarketLookup::find`.
- Uploaders must be able to see the resulting scoped asset.
- `AccessScope` applies all-of semantics.
- List, detail, download, and hold paths use `EvidenceAsset::seenBy`.
- Scope pivots are append-only for the application account.
- Database constraints and matrix coverage exist.

Remaining gaps:

- The download and hold paths have a race between authorization and the locked current row.
- Hold authorization is not covered by the full matrix.
- P1-36b items remain intentionally undelivered: inherited scopes on links and provenance hiding.

## Recommendation

**Yes after fixes.**

The branch should not merge until the authorization race is closed, because it can violate the stated guarantee that every evidence read/action follows current scopes.

## Missing tests

- Concurrent scope insertion between initial authorization and download.
- Concurrent scope insertion between initial authorization and legal hold.
- Full hold authorization matrix for all scope/classification/grant combinations.
- Direct `EvidenceUploadService` calls containing null or empty scope values.
- Multiple competitor and market scopes with partial grants, verifying strict all-of behavior.

## Unresolved assumptions / questions

- Is scope insertion guaranteed to occur only during asset creation until P1-36b, or can other application paths append scopes before then?
- Should the application re-authorize every action against the current locked row, or should scope additions be serialized with downloads/holds?

## What I did not check

I did not run tests, static analysis, migrations, CI, or database grant verification. I only inspected repository files and read-only Git output, as required.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "yes after fixes"; all three findings fixed.
1. **High (authorization not repeated under the lock): fixed.**
   - `EvidenceDownloadService` and `EvidenceHoldService` now re-run `seenBy` on the locked row inside the transaction ("not found" if no longer seen). Nothing is recorded or issued in that case.
   - Today only the upload adds scopes, inside its own transaction, so the race could not happen yet. P1-36b will add scopes on link, so the rule is documented: anything that adds a scope locks the asset row first. That serializes it with download's shared lock and hold's exclusive lock.
   - Test: `test_a_scope_added_after_the_first_check_is_enforced_under_the_lock` inserts a hidden scope as the transaction begins (the race, made deterministic) for both download and hold.
2. **Medium (hold not matrix-wide): fixed.** `test_visibility_of_scoped_evidence_follows_the_matrix_everywhere` now also runs the shared matrix over POST `/evidence/{ulid}/hold`, with the same users as two-factor-enabled directors. It covers every scope shape, every grant shape, partial grants and classification boundaries. Inactive users are in the `visibleTo()` matrix; HTTP runs use active users.
3. **Low (empty scope entries skipped): fixed.** `EvidenceUploadService::scopes()` now refuses null and empty entries, the same contract as the form. Test cases were added.

Codex's open questions:
- Until P1-36b, scopes are added only at upload, so no other path appends them.
- Both rules hold: actions re-authorize the locked row, and scope additions lock the asset row first.
