# TASK-0095 — Codex verification of the TASK-0094 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b3d8-c9bb-75a1-b966-f9dbb5f4c6de |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0095.prompt.md`, branch `feature/rereview-flags` @ `02d5d04` (the brief not yet committed) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `bcd9ade81311a5e5b95303e368052d0d9602c70228f577ea5344e92635dce1bf` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0094 findings

1. **High — reconciler deadlock: correctly dispositioned**

   **Disposition:** Correctly documented as not a defect.

   `EvidenceBlobReconciler::reconcile()` and `deletable()` lock only the `evidence_blobs` row. `reconcileLocked()` and `extendToAssets()` use non-locking asset reads (`exists()` and `max()`). No reconciler path waits for an asset lock while holding the blob lock.

   The only caller of `extendToAssets()` is `EvidenceScanService::decide()`, which already locks the asset and then the blob. Therefore, the reviewed paths do not form a blob→asset / asset→blob deadlock cycle.

   The new class docblock appropriately constrains future changes: any asset lock added to the reconciler must occur before the blob lock.

2. **Medium — scan attaching to a mismatched blob: resolved**

   `EvidenceScanService::decide()` locks the blob and checks `integrity_status`. If it is not `ok`, it changes the result to `stored_copy_failed`, clears the blob reference, and rejects the asset.

   The resulting state is valid under the migrations:

   - `status = rejected`
   - `scan_status = clean`
   - `quarantine_key = NULL`
   - `evidence_blob_id = NULL`

   The CHECK constraints permit this state. The rejection reason is recorded in the audit context, and existing reason-code handling treats it as a code rather than user-entered content.

3. **Medium — fake disk ignores versions: correctly dispositioned**

   The limitation is confined to testing fakes, which do not model object versions. Production S3 reads use `LockedObjects::version()` and require the exact non-empty `VersionId`.

   The implementation documents the limitation, and the brief reports S3 version behavior is covered by existing tests. This is acceptable as a documented test abstraction, though a version-aware fake would provide stronger fidelity.

4. **Low — payload ULID not checked against aggregate: resolved**

   `FlagCitingItems` now obtains the aggregate asset’s ULID through `EvidenceLinking::ulids()` and rejects the event if it differs from `payload['evidence_ulid']`.

   This prevents flagging one asset while auditing another. The mismatch causes delivery failure and therefore retry/dead-letter handling by the outbox infrastructure.

## 2. New findings

I found no new merge-blocking finding in the reviewed commit.

## 3. Answers to the brief’s questions

1. **Locking and `extendToAssets()`**

   The reconciler’s locking disposition is correct. It locks only the blob row; its asset queries are plain reads. `extendToAssets()` is called from the scan path after the asset and blob are already locked in asset→blob order. No reviewed caller creates the alleged cycle.

2. **`stored_copy_failed` and CHECK constraints**

   It does not violate the constraints. The rejected state with `scan_status = clean`, no quarantine key, and no blob is valid because the constraints require only that rejected assets not remain pending.

   The reason code is used in the `evidence.rejected` audit event and is consistent with the existing code-based audit definition. I found no separate enum or validator that would reject `stored_copy_failed`.

3. **Disclosure through `EvidenceLinking::ulids()`**

   The method returns only asset IDs mapped to ULIDs and is currently used by the system outbox consumer. It takes no viewer and is not exposed through a controller or viewer-facing lookup path in the reviewed changes.

   Therefore, it does not introduce viewer disclosure in this branch. If later reused by a user-facing path, it would require authorization and scope enforcement.

## 4. Recommendation

**Yes — safe to merge into main**, based on the reviewed commit and specified files.

## What I did not check

I did not execute tests, migrations, static analysis, JavaScript checks, application code, queue workers, storage operations, or network calls. I performed only read-only Git and file inspection. I did not open `.env`, other ignored environment files, or `.secrets/`.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log). #2 and #4 resolved, #1 and #3 correctly dispositioned; no new finding; recommendation "yes". Noted for later: `EvidenceLinking::ulids` takes no viewer and must stay with system consumers (its docblock says so); a version-aware storage fake would test version fidelity more strongly (the S3 path is tested with the mock handler). Nothing to change.
