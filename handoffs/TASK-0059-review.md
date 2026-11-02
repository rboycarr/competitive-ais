# TASK-0059 — Codex review of P1-31a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-03 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19e17-94d8-7b13-8729-1cb2f8ff73cd |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0059.prompt.md`, branch `feature/source-policy-review` @ `e9b4d92` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `d7efbfacc1559d26a0e65499d0d2ba69c83859f6dddb23ca12ebca4d51d1a4c3` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — merge blocker**  
   **File:** `modules/Sources/database/migrations/2026_11_16_000100_create_source_policies_table.php:69-71`  
   **Problem:** The migration uses the stored generated column `sources.current_policy_decision` as part of a foreign key. MySQL/InnoDB does not support foreign-key constraints on generated columns, so this migration is expected to fail when adding `sources_current_policy_foreign`.  
   **Scenario:** A fresh deployment or migration runs P1-31a; table creation reaches the composite FK and fails, leaving the schema partially migrated.  
   **Fix:** Do not use a generated column in a foreign key. Use a normal column maintained transactionally, or redesign the schema with a direct `current_policy_id` FK plus an enforceable database trigger/constraint strategy that guarantees the referenced policy is approved and belongs to the same source. Add a clean migration test against MySQL 8.4.

2. **Medium — not a merge blocker**  
   **File:** `modules/Sources/src/Actions/SourcePolicyService.php:156`  
   **Problem:** The service assumes `contains_personal_data` exists and is boolean. Direct callers can trigger an undefined-index/type failure before the database rules execute.  
   **Scenario:** A future job, controller, or test calls `decide()` with malformed data and receives a 500 instead of a validation failure.  
   **Fix:** Validate presence and type inside `attributes()` (`array_key_exists`, strict boolean check), or require the service to receive a validated DTO rather than a raw array.

3. **Medium — not a merge blocker**  
   **File:** `modules/Sources/src/Http/Controllers/SourceController.php:76-77`  
   **Problem:** Policy history is loaded without an explicit relationship or authorization scope. It is currently safe only because sources have no competitor/market scopes and the entire history inherits source visibility. That invariant is implicit and fragile.  
   **Scenario:** A future policy-level scope or source relationship is introduced; the detail page continues returning all policy rows after only checking the source.  
   **Fix:** Add a `policies()` relationship and document/enforce that policy visibility is derived from the visible source, or explicitly apply the same authorization contract when loading policy history.

4. **Low — not a merge blocker**  
   **File:** `modules/Sources/resources/js/Pages/Sources/Show.vue:197-200`  
   **Problem:** The UI renders `personalDataBasis` only conditionally by the boolean, but does not distinguish a malformed/missing basis from a valid “No” policy. This is mostly a presentation/auditability issue because server/database validation prevents approved personal-data policies without a basis.  
   **Fix:** Render an explicit “basis unavailable—data is invalid” state if `containsPersonalData` is true and the basis is null, and add a component test.

## Missing tests

- Run the migration on the actual supported MySQL 8.4 engine and assert it completes, especially the generated-column foreign key.
- Verify rollback after a migration failure leaves no partially-created policy table or source columns.
- Test direct `SourcePolicyService::decide()` calls with missing, non-boolean, and string `contains_personal_data`.
- Test database attempts to set `current_policy_id` to a rejected or needs-info policy after replacing the generated-column design.
- Test that a hidden source’s policy history is never returned.
- Test concurrent submit versus decision operations, not only parallel decisions.
- Test an approved source cannot be resubmitted or receive another policy decision in this slice.
- Add UI coverage for escaped terms summaries, notes, lawful-basis text, and malicious URL values.

## Recommendation

**No.** The branch is less safe than `main` because the new migration’s generated-column foreign key is expected to prevent P1-31a from deploying on the stated MySQL 8.4 target. Resolve that schema issue and add migration verification before merging.

## What I did not check

I did not run tests, migrations, static analysis, builds, or database commands, per the read-only review instructions. I also did not inspect credential files, `.env*` files, or `.secrets/`.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-03)

Recommendation "no", resting on finding 1, which is disputed with evidence below; the other findings are fixed in the next commit. Codex's log shows no credential file was opened (its only mentions of `.env` and `.secrets/` exclude them from a `git diff`).

| Finding | Disposition |
|---|---|
| 1. High: a foreign key on a generated column is unsupported, so the migration fails | **Disputed, with evidence.** MySQL refuses foreign keys on *virtual* generated columns; on *stored* generated columns they are allowed, without CASCADE / SET NULL actions (MySQL 8.4 reference, "Foreign Key Constraints", "Secondary Indexes and Generated Columns"). `current_policy_decision` is `STORED` and the key uses RESTRICT. The migration ran on MySQL 8.4.13 in every test run: the full suite on `e9b4d92` passed (310 tests, 6,954 assertions, migrations included), and `test_the_database_holds_the_review_rules` shows the key refusing a rejected policy and another source's policy (error 1452). Added: that test now also reads `information_schema` and asserts the key's columns (current_policy_id → id, id → source_id, current_policy_decision → decision) and that the column is `STORED GENERATED`. CI runs the migrations on MySQL 8.4 as well. |
| 2. Medium: direct callers can cause a type error | **Fixed.** `SourcePolicyService` checks the presence and type of every field (decision, the base version, robots, personal data as a strict boolean, texts, numbers) and answers with validation errors. Test `test_direct_calls_with_malformed_input_get_validation_errors`. |
| 3. Medium: policy history loaded without an explicit relationship | **Fixed.** `Source::policies()`, documented: a policy has no visibility of its own and is read only through a visible source; a policy-level scope would need its own check. The controller loads the history through it, after the visibility check. The hidden-source test now also checks that the page (and so the history) of a decided source is 404 for a director who cannot see it. |
| 4. Low: missing lawful basis shown as "—" | **Fixed.** Shown as "not recorded" (the database refuses that state; this only covers malformed data). |
| Missing tests | Added: the schema check (1), malformed direct calls (2), the hidden history (3), escaped terms summary, basis and decider name, and the "not recorded" basis in `Sources.test.ts`. Already covered: a rejected or needs-info policy cannot be put in force (`test_the_database_holds_the_review_rules`, error 1452); an approved source can be neither submitted nor decided again (`test_rejections_and_requests_for_information_return_the_source_to_its_proposer`). Not added: a failed migration leaving a partial schema (MySQL DDL is not transactional in any migration; the deployment runbook restores from backup, docs/10) and parallel submit against decide (they need disjoint statuses, `proposed` and `policy_review`, and both lock the source row first). |
