# TASK-0089 — Codex review of P1-43 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b0ef-af33-7e40-a892-73b08356352b |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0089.prompt.md`, branch `feature/verification-queue` @ `4e868b3` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `fefb96e1a929714367cdbbbe60eb806fde5e10c4f323149d1313c086c17464f0` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No definite security or correctness defects were identified in the reviewed diff.

The implementation consistently:

- Checks item visibility before authorization and input validation.
- Restricts review decisions to the currently assigned reviewer.
- Blocks authors and owners from reviewing.
- Rechecks assignment and state under the item row lock.
- Uses append-only `verification_reviews` grants and database CHECK constraints.
- Uses `AccessScope` for queue and item visibility.
- Locks evidence after locking the item during approval.
- Binds review records to the current version and canonical content hash.

## Missing tests

The existing tests are strong, but I recommend adding:

- Direct service-call tests bypassing HTTP controllers for every SoD rule.
- Author and owner attempts to assign themselves, start review, and decide.
- A stale decision after an item version changes.
- A reviewer whose permission is revoked between assignment and decision.
- A reviewer whose visibility scope is revoked between assignment and approval.
- Approval where evidence status or scope changes concurrently.
- Hash-difference tests for every content field, claim field, subject, citation relation, locator, and excerpt.
- Database-grant tests proving `UPDATE` and `DELETE` on `verification_reviews` fail for `cais_app`.
- Queue pagination and filter tests confirming hidden competitors/items never affect results or counts.
- Reassignment from both `submitted` and `in_review`, including rationale validation.

## Review focus answers and design calls

1. Segregation of duties: satisfactory. The service and row-lock recheck prevent stale-page decisions after reassignment. Authors and owners are rejected. The route/controller does not provide an alternate decision path.

2. Integrity: satisfactory for P1-43. `IntelligenceContent::hash()` includes the item’s substantive fields, subjects, claims, and evidence-link details using canonical JSON. The hash is recomputed from fresh database state. The append-only grant configuration and CHECK constraints are appropriate. P1-44 should independently verify the hash immediately before publishing under the item lock.

3. Visibility: satisfactory. Queue queries use `seenBy`; item transitions perform visibility checks first. Hidden items should not receive validation or authorization-specific responses.

4. Concurrency: satisfactory based on static review. Item-first locking is consistent with the workflow and evidence-link paths. The reassignment race test directly covers the most important stale-review scenario. A database-backed deadlock stress test would still be valuable.

5. Tests: broad and relevant. The main remaining gap is direct verification of database grants and exhaustive hash/change-field coverage.

Design calls:

- Director as queue owner: reasonable and consistent with the current permission model.
- Reassignment returning an in-review item to submitted: correct and auditable.
- Reviewer remaining assigned after requested changes: reasonable; resubmission correctly restarts queue age.
- Approval requiring reviewer-visible evidence: strong security choice.
- Showing rationales to item viewers: acceptable because the brief explicitly chooses full review-history visibility.
- `reviewer_id` as the actor and `assignee_id` as the target: clear and correct.

## Recommendation

Yes — safe to merge into `main`, subject to the additional tests above being added in a follow-up. I found no blocking issue in the reviewed branch.

## What I did not check

- I did not execute tests, linters, migrations, or application commands.
- I did not inspect `.env`, credential files, or `.secrets/`.
- I did not inspect the future P1-44 publish implementation, so its use of `content_hash` remains an integration assumption.
- I did not perform a live MySQL lock/deadlock test.
- I did not visually execute or browser-test the Vue pages.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). Recommendation "yes"; no findings. The suggested tests, one by one:

| Suggested test | Disposition |
|---|---|
| Direct service calls for every SoD rule | Already there: `VerificationQueueTest` calls `assign`, `startReview` and `decide` directly as well as over HTTP |
| Author or owner assigning, starting, deciding | Added: the author (also the owner here) as an assignee is refused (existing), cannot start or decide even when assigned outside the service; a separate owner likewise |
| Stale decision after the version changes | Already there (`version` refused) |
| Reviewer's permission revoked before deciding | Added (403) |
| Reviewer's visibility revoked before approving | Added (404) |
| Approval while evidence changes concurrently | Added: race test, an approval waiting on a withdrawal is refused |
| Hash differences per field | Added: every content field, subject kind, claim field and citation field changes the hash |
| UPDATE/DELETE on `verification_reviews` refused for the app account | Already there (`test_reviews_are_append_only_and_checked_by_the_database`) |
| Queue pagination and hidden items | Hidden items: the matrix test over the queue query and `GET /verification`. Added: the next page continues oldest first |
| Reassignment from submitted and from in_review | Added: from submitted (in_review was there) |
