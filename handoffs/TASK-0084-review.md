# TASK-0084 — Codex review of P1-40 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1ad1f-9d43-7aa3-b462-87dbcb639934 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0084.prompt.md`, branch `feature/intelligence-items` @ `d0d98ee` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `8e6690d160ca4b5ac429a77c862017c71e97f61d8ecc4ccca34de14f95a56ea2` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No merge-blocking defects found in the reviewed implementation.

## Missing tests

- Concurrent claim moves on the same item, verifying positions remain unique and ordered.
- Concurrent creation of an item while its competitor is archived.
- Duplicate competitor/product ULIDs and a product whose competitor is also explicitly selected.
- Markdown edge cases: entity-encoded protocols, uppercase/protocol-relative links, mailto links, autolinks, nested lists, and tables.
- Detail/list access when a visible item contains multiple hidden subjects, including hidden markets/products.
- Attempts to mutate content in every non-editable status.
- Migration rollback refusal when intelligence rows exist, plus successful empty rollback.

## Answers to review focus questions

1. **Authorization and visibility:**  
   List, detail, and write paths consistently apply `intelligence.view`/`intelligence.create`, `AccessScope`, and `statusVisible`. Hidden items produce 404s. Hidden subjects are omitted and counted on detail views. `ensureSeenBy` is rechecked after acquiring the item row lock.

2. **Concurrency:**  
   Item version checks occur under `lockForUpdate`. Claim changes lock the item before modifying claims. Claim movement swaps positions transactionally. Competitor creation uses a shared lock and archiving uses a row lock with the open-item check inside the transaction. I found no evident lock-order violation in the reviewed paths.

3. **Integrity:**  
   Content changes are restricted to editable statuses and author/owner actors. Version increments are applied for actual item/claim changes, and stale versions are rejected under lock. Earlier approvals cannot be reused because edits increment the item version. Subject removal is audited and can widen visibility of a draft; this matches the documented behavior but should remain an explicit product decision.

4. **Sanitizer:**  
   I found no bypass of `SafeMarkdown` or `MarkdownView`. Server-side Markdown is converted to an allowlisted node tree, and the client renders fixed tags without `v-html`. Images and unsafe links are removed or reduced to text.

5. **Migration:**  
   CHECK constraints, `ascii_bin` ratings, restrictive subject FKs, mutable pivot grants, no-delete grants, and rollback refusal are present and consistent with the brief.

6. **Tests:**  
   The tests cover the principal authorization matrix, hidden subjects, stale versions, claim lifecycle, archive protection, database constraints, and Markdown payloads. The concurrency and adversarial cases listed above remain gaps.

## Recommendation

Yes — safe to merge into `main`, subject to adding the missing concurrency and sanitizer regression tests before or immediately after merge.

## What I did not check

- I did not execute PHPUnit, Vitest, Pint, Larastan, ESLint, migrations, or any application runtime checks, per instruction.
- I did not inspect ignored credential files such as `.env`, `.env.*`, or `.secrets/`.
- I did not review unrelated modules or the complete architecture/documentation set beyond the files relevant to this brief.
- `rg` was unavailable in the shell, so repository searches used `git grep`/PowerShell instead.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). Recommendation "yes"; no findings. The missing tests:

| Missing test | Disposition |
|---|---|
| Markdown edge cases (encoded protocols, upper case, protocol-relative, mailto, autolinks, nesting, tables) | **Added:** `SafeMarkdownTest::test_edge_cases_keep_only_safe_links_and_allowlisted_nesting` (entity- and percent-encoded `javascript:`, upper-case schemes, `<https://…>` and `www.` autolinks, a mailto autolink, `<javascript:…>`, `file:`/`ftp:`, a nested list with a quote and a link). Protocol-relative links and tables were already covered. |
| Several hidden subjects, including products and markets | **Added:** the hidden-subjects test now hides a competitor and a product at once (count 2, neither named, on the list and the detail page); markets are unclassified reference data and always shown. |
| Content changes in every non-editable status | **Added:** `test_content_is_frozen_in_every_status_that_is_not_editable` (all seven statuses × item edit, claim add, edit, withdraw, move). |
| Duplicate ULIDs; a product whose competitor is chosen too | **Added:** `test_repeated_subjects_are_recorded_once`. |
| Concurrent claim moves; item creation while its competitor is archived | **Deferred to P1-41** (added to its acceptance), which brings evidence links and their own multi-process race tests. Every claim write runs under the item's row lock, and creation versus archiving is ordered by the competitor row lock (share lock versus `FOR UPDATE`), as reviewed. |
| Migration rollback refusal with rows, and an empty rollback | **Deferred to P1-41** (added to its acceptance): it needs committed rows (DatabaseMigrations), like `EvidenceBlobRaceTest`, and P1-41 adds a migration to the same module. |
| Subject removal widening a draft's visibility | Kept as designed (audited, only while editable); listed for the PO in the P1-40 report. |

The added tests were not run locally: the Docker daemon in WSL stopped responding during the run (calls timed out). CI runs them on the pushed commit.
