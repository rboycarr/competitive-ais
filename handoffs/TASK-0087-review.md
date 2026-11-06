# TASK-0087 — Codex review of P1-42 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1afb2-adbb-72f1-a1ab-d7b3e83ae3ce |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0087.prompt.md`, branch `feature/intelligence-submission` @ `0623c11` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `5bedbfbd0fc256066b31fc20d54f17d2cdf4ddeaa94c7665832fde76679997ff` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — hidden-item response can be `403` instead of `404`**  
   **File:** `modules/Intelligence/routes/web.php:26`  
   The `can:intelligence.submit` middleware runs before the controller’s `ensureSeenBy()` check. A user lacking `intelligence.submit` who requests a known hidden item receives authorization failure before visibility is evaluated, rather than the documented hidden-item `404`.

   **Scenario:** A user without submit permission requests `/intelligence/{hidden-item}/submit`; Laravel middleware rejects the request before the item visibility guard runs.

   **Safe fix:** Move submit authorization into the workflow after the visibility check, or use a route/controller authorization mechanism that preserves the application’s “hidden items are 404” policy. Add an authorization test for a hidden item with and without `intelligence.submit`.

## Answers to the brief’s review questions

- **Guard correctness:** The guard correctly excludes withdrawn claims, requires every remaining claim to have a `supports` link, and checks linked assets for `available` status. Contradicting/context links do not satisfy it. Submission rechecks under the item lock and share-locks cited assets, preventing concurrent withdrawal/rejection from occurring during the check.
- **Visibility of supporting evidence:** Counting support from evidence the submitter cannot see is consistent with the brief and implementation. The problem messages reveal only claim ordinal numbers, not evidence identity or content.
- **Authorization:** Author/owner and `intelligence.submit` are enforced server-side. The show page hides submission details unless the user is an author/owner with permission. The route-level ordering issue above is the only concern.
- **Concurrency:** Item → asset locking is consistent with linking and inheritance paths. Evidence scan/hold paths use asset → blob and do not appear to introduce an item/asset cycle. No concurrency test proves this, however.
- **Version integrity:** Keeping the version unchanged is consistent with the stated behavior because submission changes workflow status, not content. Later approval/review must bind to this unchanged version and content hash as planned for P1-43/P1-44.
- **Tests:** `IntelligenceSubmissionTest` covers empty claims, support/context/contradiction semantics, withdrawn/disputed claims, stale versions, permissions, editable states, freezing, and audit output. It does not prove concurrent withdrawal/link removal or hidden-item status behavior.

## Missing tests

- Hidden item submission returns `404` for users both with and without `intelligence.submit`.
- Author/owner without `intelligence.submit` cannot submit and receives no submission problem details.
- Concurrent asset withdrawal/rejection versus submission; assert no submitted item can pass with unavailable support.
- Concurrent unlink versus submission; assert the final transaction state is consistent.
- A claim with multiple support links where some are withdrawn and one remains available.
- A claim with only context/contradicting links.
- Database-level lock-order/deadlock regression coverage, ideally using MySQL rather than SQLite.

## Recommendation

**Yes after fixes** — address the hidden-item authorization ordering and add the missing authorization/concurrency regression tests before merging.

## What I did not check

- I did not run PHPUnit, Vitest, Pint, Larastan, ESLint, or any application commands.
- I did not inspect `.env`, ignored credential files, or `.secrets/`.
- I did not perform live database concurrency testing.
- I reviewed the requested branch diff and relevant source/tests only; unrelated modules and the full architecture/document set were not exhaustively audited.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). Recommendation "yes after fixes".

| # | Finding | Disposition |
|---|---|---|
| 1 | Medium: `can:intelligence.submit` route middleware answered 403 for a hidden item, before visibility | **Fixed:** the submit route needs only `can:intelligence.view` (like the item page); `IntelligenceWorkflowService::submit` checks visibility first (404), then `intelligence.submit`, then the author or owner. Test: `IntelligenceSubmissionTest::test_a_hidden_item_is_not_found_whatever_the_permissions` (a user without and a user with `intelligence.submit`, both 404 on submit and on the page). The claim and evidence-link routes keep their `can:intelligence.create` middleware (P1-40/P1-41 pattern, reviewed in TASK-0084/0085); listed for the PO. |

| Missing test | Disposition |
|---|---|
| Hidden item 404 with and without `intelligence.submit` | **Added** (above). |
| Author or owner without `intelligence.submit`: refused, no problem details | **Added:** the data_collector owner gets 403 on submit and `submission: null` on the page. |
| Concurrent evidence withdrawal versus submission | **Added:** `IntelligenceRaceTest::test_a_submission_waiting_on_evidence_being_withdrawn_is_refused` (separate processes: the withdrawal holds the asset's lock; the waiting submission sees the committed withdrawal and is refused). |
| Concurrent unlink versus submission | Not added: both run under the item's row lock with the version read, so the second fails on the version (`IntelligenceRaceTest` proves one winner per version for claim and link changes). |
| Several support links, some withdrawn, one available | **Added** to `test_every_claim_needs_available_supporting_evidence`. |
| A claim with only context or contradicting links | Already covered: claim 2 in `test_every_claim_needs_available_supporting_evidence`. |
| Lock-order and deadlock regression on MySQL | The race tests run on MySQL 8.4 in separate processes; a general deadlock suite is part of the hardening sprint (1.5). |
