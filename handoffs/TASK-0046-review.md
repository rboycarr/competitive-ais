# TASK-0046 — Codex review of P1-25 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (05:19:19–05:20:16 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19ab2-9df0-71a0-a83a-0986ef7479b8 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0046.prompt.md`, branch `feature/p1-25-uniqueness-under-load` @ `39976bf` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `1aa9aee6e219941bc8d540b32c4eed4087c69510ebecdcb9b14581ecdc3e2b3c` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — harness can hang indefinitely on a stuck child**

   **File:** `tests/Support/Concurrently.php:57-58`

   **Problem:** The parent calls `pcntl_waitpid($pid, $status)` without a timeout or kill/recovery path. A child blocked on a database lock, deadlock retry, connection failure, or infinite loop can hang the entire CI worker indefinitely.

   **Scenario:** A service deadlocks or loses its database connection after forking. The child never writes its result or reaches `SIGKILL`; the parent waits forever and the test suite never reports a failure.

   **Safe fix:** Add bounded polling with `WNOHANG`, a deadline, and forced termination of remaining children after timeout. Record a deterministic failure result and always clean up the temporary directory.

2. **Medium — removal tests can pass without proving successful tagging or product creation**

   **File:** `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:137-151`

   **Problem:** The brand case compares product rows with successful worker outcomes, but the tag case only checks whether the tag was kept or removed. It never verifies that successful tagging outcomes produced exactly the corresponding number of `taggables` rows. Similarly, the currency case does not assert that successful market outcomes correspond to persisted markets.

   **Scenario:** A regression causes all tag requests to return `ValidationException` while the removal request also returns a validation error. The tag remains, `links` is zero, and the test passes. The same vacuity is possible for market creation, allowing the currency invariant to appear satisfied without exercising the competing operation.

   **Safe fix:** Assert:

   - tag link count equals the number of successful tag outcomes;
   - each successful tag operation has a persisted link;
   - market count equals successful market outcomes;
   - the currency update result and persisted `minor_units` agree with the winning operation.

3. **Medium — the category assertion is weaker than the stated category-tree guarantee**

   **File:** `modules/Registry/tests/Feature/RegistryConcurrencyTest.php:106-124`

   **Problem:** The test checks path consistency and only rejects the specific final state where both `A.parent_id == B.id` and `B.parent_id == A.id`. It does not verify that every successful move produced a valid final parent, that the moved subtree remained attached consistently, or that no category was silently lost or unexpectedly rewritten.

   **Scenario:** A concurrency bug causes one move to overwrite another and leaves `C` detached or attached to an unintended category. If paths are recomputed consistently, `CategoryTree::inconsistencies()` returns empty and the test passes.

   **Safe fix:** Assert the final parent relationships against the set of successful move outcomes, verify all three category rows still exist, and verify `C`’s descendants/path/depth match its final parent.

4. **Low — the harness does not validate that all expected children were actually forked**

   **File:** `tests/Support/Concurrently.php:36-48`

   **Problem:** If `pcntl_fork()` fails after some children have already been started, the method throws immediately without waiting for or terminating the already-created children and without removing the temporary directory.

   **Scenario:** CI process limits or transient OS resource exhaustion cause a later fork to fail. Earlier children continue running against the test database while PHPUnit proceeds to fail, potentially contaminating subsequent tests.

   **Safe fix:** On fork failure, terminate and reap all already-created children, remove result files and the temporary directory, then rethrow the exception.

## Missing tests

- A harness timeout test covering a child that never reports.
- A fork-failure cleanup test.
- A child fatal-error or forced-termination test confirming the parent fails clearly and reaps it.
- Tagging assertions matching persisted links to successful worker outcomes.
- Market-creation assertions matching persisted markets to successful worker outcomes.
- Currency assertions checking the final `minor_units` value and update outcome, not only audit-event IDs.
- Category assertions covering:
  - successful move outcome to final parent;
  - subtree path/depth preservation;
  - concurrent moves of a non-root subtree with descendants;
  - two concurrent moves of the same category to unrelated parents.
- Parallel unique-name tests for competitor-owned brands, not only own brands.
- Parallel duplicate market-code creation and duplicate currency-code creation.
- Parallel category creation under the same parent, not only root categories.
- Parallel region creation below the same parent.
- Relationship tests for finite intervals, boundary-touching intervals, reverse-direction relationships, and concurrent updates/removals.
- A CI-environment guard proving the test uses MySQL 8.4 and the intended test credentials.

## Recommendation

**Yes after fixes.**

The branch adds useful coverage and the harness correctly converts a missing child result into a failed assertion rather than a passing test. However, the timeout/cleanup weakness can hang or contaminate CI, and several removal/category assertions are weaker than the stated invariants.

## What I did not check

- I did not run tests, static analysis, migrations, or mutation tests, per the read-only review instructions.
- I did not inspect the complete historical implementation of the registry services beyond the services directly called by this test.
- I did not verify the reported test results in the brief.
- I did not inspect CI configuration or database-server settings outside the changed files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding | Disposition |
|---|---|---|
| 1 | High: the parent waits without a limit | **Fixed.** `Concurrently::run()` polls with `WNOHANG` until a deadline (120 s by default), then kills and reaps any task still running and reports it as `timeout`. A task that dies without reporting is reported as `no result`. The temporary directory is always removed. `assertCleanOutcomes()` treats both as failures. |
| 2 | Medium: the tag and market scenarios can pass vacuously | **Fixed.** The successful requests must now match the stored rows: products, tag links (also per competitor) and markets. The removal outcome must match whether the row was kept. The final decimal places must match the update's outcome, and a refused update must have met a market. |
| 3 | Medium: the category check is weaker than stated | **Fixed.** All four categories must still exist. A and B each end under their successful move's target or where they were, C under one of its successful targets (or still under A), and D stays under C. D is a grandchild that moves with C, so `CategoryTree::inconsistencies()` also checks a moved subtree's paths and depths. |
| 4 | Low: a failed fork leaves earlier children running | **Fixed.** A `finally` block kills and reaps every child still running and removes the temporary files before the error propagates. |

**Missing tests:**

| Suggested test | Status |
|---|---|
| Harness tests | **Added** in `tests/Feature/ConcurrentlyTest.php`: outcomes come back in order, a timed-out task is killed within the deadline, and a task that dies without reporting is reported. A forced fork failure isn't simulated; the cleanup path is the same `finally` block. |
| More unique-name cases | **Added:** competitor-owned brands, duplicate market codes, duplicate currency codes, a subcategory under one parent, a subregion under one parent. |
| More relationship cases | **Added:** four back-to-back quarters must all succeed (no over-refusal); crossing updates that are each fine alone allow exactly one winner, and the stored dates match the winner. |
| Two moves of one category to unrelated parents | Covered by C's two concurrent moves. |
| CI guard for MySQL 8.4 and the test credentials | Already enforced: `TestCase::createApplication()` refuses non-`_test` databases, and `DatabaseAccountsTest` covers the accounts. |

**Two real bugs found by the new tests and fixed:**

1. **Parallel tagging deadlocked.** `CompetitorTagService::tag()` checked for an existing link with `lockForUpdate()`. For a missing link that takes a gap lock, so two requests tagging different competitors with one tag both held the gap and deadlocked on their inserts (MySQL 1213, a 500 for the user).
   - The new scenario `test_tagging_different_competitors_with_one_tag_in_parallel_succeeds` failed in 5 of 5 runs, with 1 to 3 of 4 requests killed.
   - Fix: a plain read; the primary key still refuses a concurrent duplicate, which is treated as "already tagged".
   - After the fix: 5 of 5 runs pass.
2. **Brand removal deadlocked with product creation.** `ProductService` read the brand by ULID under a shared lock, which locks the ULID index entry before the row. `BrandService::remove()` locks the row first and then needs the index entry to delete it.
   - Seen in 2 of about 7 runs of the removal scenario.
   - Fix: find the brand's ID with a plain read, then lock the row by its key.
   - After the fix: 6 of 6 runs (18 rounds) pass.

The other locked existence checks guard removals. They take the parent row's lock before the child gap, in the same order as the competing inserts, and the removal scenarios exercise them.
