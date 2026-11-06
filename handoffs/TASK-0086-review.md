# TASK-0086 — Codex review of P1-36b (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1af3e-f6e7-72b3-8244-11c89bc2659b |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0086.prompt.md`, branch `feature/evidence-inherited-scopes` @ `a0f023b` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `624218bb13f6ca63e62b3bf2387a145769fdb5503c632d34bcfe9cd957557c38` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
Findings

No security or correctness findings identified in the reviewed diff. The implementation consistently:

- Locks evidence assets before visibility checks.
- Uses `AccessScope` for visibility decisions.
- Preserves inherited scopes monotonically.
- Uses item → asset lock ordering.
- Rolls back inherited scopes when citation/edit refusal occurs.
- Includes custody and audit records for inheritance.

Answers to review focus questions

1. Visibility and locking  
   The locking approach is correct under MySQL `REPEATABLE READ`: asset locking reads establish the current row state, and scope checks occur after the asset lock. Downloads and holds re-check visibility while holding the asset lock, preventing stale authorization decisions relative to inheritance.

2. Deadlocks and lock order  
   The reviewed paths use item → asset ordering for linking/editing and asset → blob ordering for holds. Asset IDs are locked in ascending order during bulk edits. No conflicting order was found in the reviewed files.

3. Monotonicity and completeness  
   Evidence inherits scopes when first cited and when item competitors/markets are added later. Withdrawn claims are included during item edits, and unlinking or subject removal does not remove inherited scopes. Product subjects correctly contribute their competitor scope through the normalized subject set.

4. Refusal rule  
   Requiring the writer/editor to retain visibility of evidence they currently see is appropriate. Allowing edits involving evidence already hidden from the editor is consistent with the brief and avoids blocking legitimate item maintenance.

5. Audit and custody  
   Custody events contain the item ULID and exact added competitor/market IDs. Audit events contain the item ULID and counts, with detailed IDs retained in custody history. This is sufficient to reconstruct the inheritance cause when both records are available.

6. Tests  
   `EvidenceInheritanceTest` covers matrix visibility, recording, monotonicity, later subject additions, and refusal. `IntelligenceRaceTest` covers a waiting citation observing committed inherited scopes.

Missing tests

- Concurrent item edit adding scopes to the same item from two requests, verifying no duplicate pivot rows or duplicate inheritance events.
- Concurrent linking of different claims on the same item and asset.
- Download and legal-hold races against inheritance for both “inherit commits first” and “download/hold commits first” orderings.
- Inheritance through a product-only item, verifying the product’s competitor is inherited.
- Inheritance through withdrawn claims after later subject additions.
- Duplicate or malformed scope IDs reaching `inherit()`, including foreign-key failure rollback behavior.
- Audit/custody rollback when one inherited pivot insertion or audit write fails.

Recommendation

Yes after fixes.

The reviewed implementation appears safe to merge functionally, but I recommend adding the concurrency and rollback tests above before merging because the existing tests do not fully cover downloads, holds, product-derived scopes, or concurrent inheritance attempts.

What I did not check

- I did not execute tests, static analysis, migrations, or application code.
- I did not inspect every file under `modules/Intelligence/src/Actions/**` or every platform access implementation outside the named files.
- I did not inspect `.env`, `.env.*`, or `.secrets/`, as prohibited.
- I did not independently verify the PO’s reported test results.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). Recommendation "yes after fixes", with no findings: the fixes are the missing tests.

| Missing test | Disposition |
|---|---|
| Concurrent edits of one item adding scopes: no duplicate rows or events | **Added:** `IntelligenceRaceTest::test_concurrent_subject_edits_from_one_version_inherit_once` (three processes, two different new subjects, one version: one winner, the evidence holds exactly the winner's subject, inheritance recorded once). |
| Concurrent linking of different claims of one item to one asset | **Added:** the link race in `test_concurrent_claim_moves_and_links_from_one_version_have_one_winner` now cites the asset from three different claims and relations: one winner per round. |
| Download and hold races against inheritance, both orders | Covered by `EvidenceScopeTest::test_a_scope_added_after_the_first_check_is_enforced_under_the_lock`: an `inherited` scope committed between a download's or hold's first check and its lock is enforced under the lock (404). In the other order, the download or hold committed before the scope existed, which was allowed at that time; inheritance waits for the asset lock and never undoes it. |
| Inheritance through a product-only item | **Added:** `EvidenceInheritanceTest::test_product_subjects_and_withdrawn_claims_pass_their_scopes_on` (the product's competitor is inherited). |
| Inheritance through withdrawn claims after later subject additions | **Added** in the same test (a withdrawn claim's evidence inherits a competitor and a market added later). |
| Duplicate or malformed scope IDs reaching `inherit()`, FK failure rollback | **Added:** `test_inherit_writes_repeats_once_and_an_unknown_id_changes_nothing` (repeats written once, a second call adds nothing and records nothing, an unknown competitor ID fails the whole call with no scope or record). |
| Audit or custody rollback when one write fails | **Added:** `test_a_failed_write_leaves_no_inherited_scope` (the citation's custody write fails after inheritance: no scope, link, custody, audit or version change remains). |
