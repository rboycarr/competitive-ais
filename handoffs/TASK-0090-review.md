# TASK-0090 — Codex review of P1-44 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b155-ed43-7ca3-9ad2-8c86168f2dea |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0090.prompt.md`, branch `feature/intelligence-publication` @ `8e3ed41` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `36dda10555a5a28c40ea78aa50bf1c3f2f7ebf1de5e82b32cbc1440a0cda681b` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — `supersedes_id` is not immutable at the database/application boundary**

   File: `modules/Intelligence/database/migrations/2026_11_24_000100_create_intelligence_tables.php:37`; `modules/Intelligence/src/Actions/IntelligenceItemService.php:137`

   `supersedes_id` is described as “never changed,” but the schema only adds a nullable foreign key. Any code path with update access to `intelligence_items` can change or clear it. This can detach a replacement from its original or redirect it to another item after approval.

   Scenario: an administrative/data-maintenance path updates `supersedes_id` on a draft or published replacement. Publication then locks and supersedes the newly referenced item, producing an incorrect publication lineage.

   Safe fix: enforce immutability in the write model/service and add a regression test proving ordinary item updates cannot alter it. If database-level protection is required by the integrity model, add an update trigger or isolate this column behind a dedicated append-only transition mechanism.

2. **Medium — replacement drafting is not serialized with retraction**

   File: `modules/Intelligence/src/Actions/IntelligenceItemService.php:108-137`

   `draftReplacement()` checks that the source is published before opening its transaction, but it does not lock the source item. A concurrent retraction can commit between the check and draft creation, leaving a replacement draft pointing to an already-retracted item.

   Scenario: replacement drafting and retraction run concurrently. The draft is successfully created, but can never publish because `publish()` requires the superseded item to still be published.

   Safe fix: lock the source item inside the transaction before checking `status`, then create the draft while holding that lock. Add a race test against retraction.

3. **Low — replacement visibility is validated only after a non-scoped lookup**

   File: `modules/Intelligence/src/Actions/IntelligenceWorkflowService.php:276-282`

   Retraction first resolves the replacement ID using a global ULID lookup before applying visibility checks inside the transaction. The final authorization is correct, so this does not disclose the item directly, but it performs an unnecessary lookup on hidden records and makes the validation path dependent on existence rather than visibility.

   Safe fix: resolve the replacement through `IntelligenceItem::seenBy(...)`, or deliberately preserve the current behavior but add a test confirming hidden replacement IDs produce the same response and timing-independent error as nonexistent IDs.

## Answers to the brief’s review-focus questions

1. **Publish invariant:** Mostly enforced correctly. Publication rechecks status, submitted version, author/owner exclusion, approving-reviewer exclusion, current-version approval, matching content hash, Director role where required, and current evidence availability under the item lock. I found no ordinary service path that bypasses these checks.

   Design call: keeping the owner excluded is sound because the owner can edit the item and therefore should not publish it.

2. **Locking:** The publication and retraction paths acquire item locks in ID order, which is appropriate for two-item operations. The evidence checks occur after the item lock, consistent with the existing item-then-evidence ordering. The main gap is replacement drafting, which does not lock the source item and can race with retraction.

   Design call: allowing several replacement drafts is reasonable; publication-time refusal of all but the first is correctly serialized.

3. **Visibility:** Hidden source items are checked before input validation and return 404. Linked items are filtered through `seenBy`, so hidden replacement/supersession targets are not named. The replacement selection list is also visibility-scoped.

   Design call: retaining readable banners for retracted and superseded items is appropriate, provided the linked item remains visibility-filtered as implemented.

4. **Integrity:** Retractions have a unique item FK, restrictive foreign keys, nonblank reason and self-replacement checks, and append-only database grants. The supersession link has a foreign key but lacks actual immutability enforcement; this is the principal integrity concern.

## Missing tests

- Concurrent `draftReplacement()` versus `retract()`.
- Attempt to alter or clear `supersedes_id` through every ordinary item update path.
- Concurrent role revocation/status suspension while publishing a confidential or high-impact item.
- Hidden replacement ID versus nonexistent replacement ID, asserting identical authorization behavior.
- Repeated publication/retraction requests under concurrency, asserting exactly one state transition and one append-only record.

## Recommendation

**Yes after fixes.** The publication invariant and visibility controls are substantially sound, but the supersession immutability gap should be resolved, and replacement drafting should be serialized with retraction.

## What I did not check

I did not run tests, migrations, static analysis, frontend checks, or database commands. I also did not inspect ignored credential files, `.secrets/`, or make network/package/deployment changes.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log quotes the project docs that mention them). Recommendation "yes after fixes".

| # | Finding | Disposition |
|---|---|---|
| 1 | High: `supersedes_id` not immutable | **Fixed in the application; database enforcement added to P1-52.** No application path changed it (the item service's edits never write it). `IntelligenceItem` now refuses any update that changes or clears it once set, or sets it on anything but a draft (`LogicException`); test `test_the_replaced_item_is_never_changed` (an edit carrying `supersedes_id` keeps the original; changing, clearing, or setting it on a published item is refused). A trigger for direct SQL joins the P1-52 list (database-held immutability, Sprint 1.5) |
| 2 | Medium: drafting a replacement not serialized with retraction | **Fixed.** `draftReplacement` share-locks the original inside its transaction and re-checks that it is published; a retraction (FOR UPDATE) waits for the draft, or the draft's locking read sees it. Race test `a replacement drafted during a retraction is refused` (separate processes) |
| 3 | Low: replacement resolved before visibility | **Fixed.** `retract` resolves the replacement through `IntelligenceItem::seenBy`; hidden and missing IDs take the same path and give the same message (already tested for both) |
| — | Suggested tests | Added: drafting versus retraction (above); two directors publishing one item at once (one publication, one review row, one audit event). Already there: publishing twice in a row and retracting twice (refused, one row; the retraction's item key is unique), hidden versus missing replacement. Not added: role revocation during publication: `lockedHoldsRole` reads the director's row under a share lock in the publishing transaction, and role changes lock the same row (the pattern TASK-0031 #2 tested for `lockedActiveHolder`) |
