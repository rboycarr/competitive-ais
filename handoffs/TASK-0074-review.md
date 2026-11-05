# TASK-0074 — Codex review of P1-35 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a9da-0fd7-7bd2-b962-b22118dbdf57 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0074.prompt.md`, branch `feature/evidence-legal-hold` @ `54e63de` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `6572d160bbeb73c217c4fd80f31343a857c4c12fed50b6eeaa442e30dfb145ab` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No merge-blocking findings.

- **Low — `modules/Evidence/src/Actions/EvidenceHoldService.php:68-74`**  
  The blob-hold OR is enforced by the service workflow, but not by a database constraint or trigger. Any future status/asset mutation path that changes `legal_hold` directly could leave `evidence_blobs.legal_hold` stale and allow retention cleanup to misclassify the blob.  
  **Scenario:** a future administrative job updates an asset’s hold flag without calling `EvidenceHoldService`; the blob remains `legal_hold = 0`.  
  **Safe fix:** keep all hold mutations behind the service and add an invariant/reconciliation job or database-maintained mechanism before implementing retention deletion. This is not a blocker for the current story because the existing mutation path is centralized and tested.

## Acceptance and ADR assessment

P1-35 is met for the implemented path:

- A per-asset `legal_hold` flag exists.
- Set/release operations require a reason.
- Only available evidence can be held.
- Custody events are recorded for both setting and releasing.
- The blob hold is recomputed as the OR of all referencing asset holds.
- S3 legal hold is applied to the stored object version.
- Holds are pushed on before a hold-setting transaction commits.
- Releases are pushed after commit while rechecking the blob row, preventing a concurrent sibling hold from being overwritten.
- Store failures fail closed or leave the object held.

ADR-0007’s stated hold rules are met for this branch. Per-asset retention dates and the retention-deletion job remain intentionally undelivered items for P1-37/P1-38, as documented; they are not required to complete this P1-35 slice.

## Recommendation

**Yes, safe to merge into `main`.**

The remaining low-severity concern is a future-proofing/invariant issue, not a demonstrated regression or violation of the current acceptance guarantee.

## Missing tests

- A reconciliation test/job for database blob-hold state versus the S3 object-version hold state.
- A test proving a future/direct asset mutation cannot bypass the service invariant.
- A concurrent integration test with two transactions setting/releasing holds on sibling assets sharing one blob.

## Unresolved assumptions / questions

- Should P1-38 require a periodic repair mechanism for database/object legal-hold drift before deletion becomes available?
- Will all future asset mutation paths be required to use `EvidenceHoldService` and the existing status constraints?

## What I did not check

- I did not execute tests, static analysis, builds, migrations, or application probes, per the read-only review instructions.
- I did not inspect ignored credential files such as `.env`, `.env.*`, or `.secrets/`.
- I did not review unrelated branches or changes outside `git diff 897f5fa..HEAD`.
- `rg` was unavailable in the shell, so repository searches used PowerShell inspection instead.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "yes"; one low finding, not a blocker.
- **Low (blob hold kept by the service, not the database): accepted, deferred to P1-38, added to that story's acceptance in `docs/11`.** Today the only path that changes `legal_hold` is `EvidenceHoldService`. The asset's CHECK also stops a held asset from leaving `available`. Before anything can delete a blob, P1-38's deletion job will:
  - recompute the hold from the assets under lock (`EXISTS` a held asset), never trusting `evidence_blobs.legal_hold` alone;
  - read the object version's legal hold from the store and refuse on either;
  - include a reconciliation of the database and object holds, run before deletion becomes available.
  The same answers Codex's two open questions.
- **Missing tests:**
  - The concurrent sibling set/release test belongs with P1-38's race and failure-injection tests. Its lock order and post-commit push are covered by `test_a_shared_blob_stays_held_until_its_last_held_asset_is_released` and the store-failure tests.
  - The reconciliation test comes with the reconciliation itself, in P1-38.
