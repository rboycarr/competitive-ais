# TASK-0077 — Codex review of P1-37 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1aabf-d1d5-7431-9a28-fd75ced81f80 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0077.prompt.md`, branch `feature/evidence-source-policy` @ `f191fb4` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `2316c6be25ab645039225a03561fcf13826cbb007de06e8638904fb58e574db8` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — not a merge blocker**  
   **File:** `modules/Evidence/database/migrations/2026_11_22_000100_add_source_policy_to_evidence_assets.php:41–46`  
   **Problem:** The rollback assumes MySQL created an index named `evidence_assets_source_policy_foreign`. This is not explicitly declared as an index and is not verified by the migration. Rollback may fail on a schema where the automatically created index has a different name.  
   **Scenario:** Running this migration’s `down()` on an empty database reaches `dropIndex()` and fails because the inferred index name does not exist.  
   **Fix:** Declare the composite index explicitly with a stable name, or inspect/drop the actual index deterministically. Add an empty-schema rollback test.

2. **Medium — not a merge blocker for P1-37; deferred consistently to P1-52**  
   **Files:** `modules/Evidence/database/migrations/2026_11_22_000100_add_source_policy_to_evidence_assets.php:16–18`; `modules/Evidence/src/Actions/EvidenceUploadService.php:122–124`  
   **Problem:** `source_id` and `source_policy_id` are not immutable at the database level. A direct SQL update can replace a capture’s recorded policy with another valid policy for the same source after insertion.  
   **Scenario:** An account with database write access updates an existing asset from policy version 1 to policy version 2. The foreign keys remain valid, but the historical authorization is falsified.  
   **Fix:** Deliver the planned P1-52 trigger/privilege enforcement and direct-update tests before treating provenance as tamper-resistant. The current deferral is documented and does not violate this slice’s stated acceptance.

3. **Low — not a merge blocker**  
   **Files:** `modules/Evidence/tests/Feature/EvidenceSourcePolicyTest.php:168–185`  
   **Problem:** The concurrency test verifies that a status change made before the upload transaction’s source lookup is observed, but it does not exercise a genuine concurrent `SourceLifecycleService` transaction acquiring `FOR UPDATE` and competing with the upload’s `FOR SHARE`.  
   **Scenario:** A future change alters transaction timing or isolation behavior, and the event hook still passes while the real lock ordering is incorrect.  
   **Fix:** Add a two-connection integration test that blocks one transaction on the source row, then releases it and asserts the upload either records the prior policy or is rejected according to commit order.

## P1-37 acceptance

- `source_policy_id` recorded: **Met.** Uploads record both `source_id` and the policy ID returned by the in-transaction lookup. The composite foreign key ensures the policy belongs to the same source and is approved.
- Upload blocked for unapproved sources: **Met.** Unknown, hidden, proposed, in-review, suspended, and retired sources are rejected before object storage; the source is checked again inside the transaction.
- Policy-change test: **Met.** The feature tests cover suspension, reinstatement, a new approval, later capture using the new policy, and preservation of the earlier policy.

Undelivered or intentionally deferred:

- Database-level immutability of provenance columns remains deferred to P1-52.
- Retention derived from the captured policy remains deferred to P1-38.
- A true multi-connection lock-contention test is not present.
- The migration rollback path lacks an explicit empty-schema verification.

## Recommendation

**Yes after fixes.**

The branch meets the stated P1-37 acceptance and does not show a problem that makes it less safe than `main`. I recommend fixing or explicitly verifying the rollback index behavior before merge, and adding the stronger concurrency test as hardening.

## Missing tests

- Empty-database rollback of the evidence source-policy migration.
- Concurrent `SourcePolicyService` approval/suspension versus evidence upload using separate database connections.
- Direct SQL mutation tests for `source_id` and `source_policy_id` should be added when P1-52 is implemented.
- A test that a valid policy from the same source but no longer current cannot be used for a new capture.
- A test confirming hidden-source placeholders never expose name, status, policy version, or timestamps.

## Unresolved assumptions / questions for the Product Owner

- Is the `{visible: false}` provenance placeholder explicitly approved for this slice, given that it reveals a source was recorded while hiding its identity?
- Should auditors with evidence visibility but without `source.view` eventually receive policy metadata through a dedicated provenance permission?
- Is rollback of this migration expected to work on an empty database, or is rollback intentionally unsupported except when no source columns have been populated?

## What I did not check

- I did not run tests, static analysis, frontend checks, migrations, or application commands.
- I did not inspect `.env`, other ignored environment files, or `.secrets/`.
- I did not perform a live concurrent transaction test or verify MySQL’s generated index name.
- I did not review unrelated modules beyond the changed files and the explicitly requested source-policy files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "yes after fixes"; acceptance met. No credential file was opened (Codex's log).

1. **Medium, rollback index name: verified, no code change.**
   - MySQL names the index it creates for a foreign key after the constraint's symbol, here `evidence_assets_source_policy_foreign`. P1-31a's `sources_current_policy_foreign` relies on the same rule.
   - The full backend suite (415 passed) rolls this migration back in every DatabaseMigrations teardown.
   - The new `EvidenceSourcePolicyLockTest::test_the_migration_rolls_back_and_applies_again_when_no_evidence_names_a_source` rolls it back and applies it again through the migrator. It asserts that the three columns and three constraints are gone, then back.
2. **Medium, immutability after insert: deferred to P1-52, as Codex accepts.**
   - Evidence `source_id` and `source_policy_id` are in P1-52's acceptance in this branch (`docs/11`).
   - Until then, only `EvidenceUploadService` writes them, at insert.
3. **Low, real lock contention: fixed.** `EvidenceSourcePolicyLockTest::test_a_capture_racing_a_suspension_reads_the_status_after_the_suspension_commits` runs in separate processes (`Concurrently`):
   - A suspension holds the source row for update and commits after 3 s.
   - The capture starts at 1 s, so its first check still sees `approved`.
   - The capture must be refused, with no asset and no quarantine object.
   - With the share lock removed from `EloquentSourceLookup::forCapture`, the test fails. Both tasks report "ok": the asset insert waits on the foreign key's own lock, then records the policy of a source suspended before the capture committed. So the explicit lock is what makes the capture read the status after the suspension.

**Missing tests Codex lists:**
- *A valid but no longer current policy of the same source cannot be used for a new capture:* the client never sends a policy. The service records the policy in force under the lock, and `test_a_policy_change_applies_to_later_captures_and_never_rewrites_earlier_ones` asserts that the capture after the new approval records version 2.
- *The placeholder exposes nothing:* `test_a_source_the_viewer_cannot_see_is_shown_as_a_placeholder_without_its_name` asserts that `asset.source` is exactly `{visible: false}`.
- *Direct-SQL immutability tests:* with P1-52.

**Questions for the PO,** in the P1-37 report:
- the placeholder (it reveals only that a source was recorded, like the grants page's "a competitor you cannot see");
- a provenance permission for auditors without `source.view` (all seeded roles with `evidence.view` hold `source.view` today).
