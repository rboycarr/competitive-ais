# TASK-0061 — Codex review of P1-31b (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-03 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19eb5-95ef-7831-9fa6-7adba50116eb |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0061.prompt.md`, branch `feature/source-lifecycle` @ `d66ecb4` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `d59e7fdb82be23617917060192db916336566f0227c70fda4fe605ed68f1b8bb` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — not a merge blocker**  
   **File:** `modules/Sources/database/migrations/2026_11_17_000100_create_source_status_changes_table.php:30`  
   **Problem:** `changed_at` uses MySQL `TIMESTAMP(6)`, while the project database convention requires `DATETIME(6)` and explicitly avoids the 2038 limit.  
   **Scenario:** A lifecycle record created after the MySQL timestamp range becomes invalid or cannot be stored.  
   **Fix:** Use `$table->dateTime('changed_at', 6)`.

2. **Medium — not a merge blocker; documented P1-52 hardening**  
   **File:** `modules/Sources/database/migrations/2026_11_17_000100_create_source_status_changes_table.php:35-43`  
   **Problem:** The transition CHECK validates history rows, but does not constrain the `sources.status` column itself. A privileged direct SQL update can change a source from `retired` back to `approved`, or from `suspended` to `approved`, without a lifecycle row or new policy approval.  
   **Scenario:** A migrator/operator executes `UPDATE sources SET status='approved' ...`; the source becomes usable with no recorded reinstatement and potentially relies on an old policy.  
   **Fix:** Add database-enforced source status transition protection in P1-52, using triggers or restricted update procedures/privileges. Include an integration test for every forbidden direct status transition. This is explicitly deferred in the branch documentation, so it is not a blocker for this slice unless P1-52 is required before any production use.

No merge-blocking flaw was found in the reviewed application paths. Reinstatement correctly leads to `policy_review`; `SourcePolicyService` locks the source first, rejects decisions by the proposer, and requires a new approved policy before returning to `approved`. Rejected re-reviews intentionally return to `proposed` while retaining the historical `current_policy_id`, which is not treated as in force unless status is `approved`.

## Missing tests

- Direct database attempts to change `sources.status` from `retired` to another status and from `suspended` directly to `approved`, once P1-52 hardening is implemented.
- A migration/schema assertion that `changed_at` is `DATETIME(6)`, not `TIMESTAMP(6)`.
- A direct service test confirming that a rejected re-review preserves the old `current_policy_id` but does not make the source usable.
- A test that a source retired while `policy_review` cannot later be submitted or decided.
- A test confirming hidden-source behavior for status-history access after classification changes.
- A test for note boundary behavior at exactly 10,000 characters and for non-string direct inputs through the HTTP request layer.

## Recommendation

**Yes after fixes** — fix the `DATETIME(6)` schema mismatch before merging. The missing database enforcement for source status transitions is a planned P1-52 hardening item and is not by itself a blocker for this branch.

## What I did not check

- I did not run tests, static analysis, frontend checks, migrations, or grant application commands.
- I did not inspect `.env`, other credential files, or `.secrets/`.
- I did not review future P1-37 evidence-capture enforcement beyond the documented interface, because that implementation is not present in this branch.
- `rg` was unavailable in the shell, so text searches used PowerShell alternatives.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-03)

Recommendation "yes after fixes"; fixed in the next commit. Codex's log shows no credential file was opened (it quoted documentation and configuration that mention `.env`, nothing more).

| Finding | Disposition |
|---|---|
| 1. Medium: `changed_at` is TIMESTAMP(6), the convention is DATETIME(6) | **Fixed**, and the same mistake in P1-31a: `source_status_changes.changed_at` is DATETIME(6); a new migration (`2026_11_17_000200`) converts `source_policies.decided_at` (merged in P1-31a) to DATETIME(6); every connection runs in UTC, so stored values do not change. A wider check found 43 TIMESTAMP columns already on `main`, from Laravel's `timestamps()` and framework tables (22 tables, including P1-30's `sources`). New `SchemaConventionsTest` fails on any TIMESTAMP column not in that list of known debt, and on a listed column that has been converted, so no new one is added. Converting the existing ones is raised with the PO as its own story. |
| 2. Medium: the database does not hold the source's own status transitions | **Deferred to P1-52, as documented.** P1-52's acceptance criteria now name source status transitions (retired is final; suspended returns to approved only through review and a new approval) with a direct-UPDATE test per forbidden transition. Until then, only the migrator account (operators) could make such an update; the app account's code paths all go through the two services. |
| Missing tests | Added: a rejected re-review keeps the old approval on record but not in force (`test_a_rejected_re_review_keeps_the_old_approval_on_record_but_not_in_force`); a source retired while awaiting review can be neither decided nor submitted; a note of exactly 10,000 characters is accepted; array inputs through the HTTP layer get validation errors; the DATETIME check (`SchemaConventionsTest`). Deferred with P1-52: direct status-transition updates. Not added: hidden history after a classification change, because no story changes a source's classification yet (sources cannot be edited); the history is read only through a visible source (`Source::statusChanges()`), and the page of a hidden source is 404 (tested). |
