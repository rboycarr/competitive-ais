# TASK-0064 — Codex review of P1-32a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-03 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19fc7-348f-7d72-8936-52e343b73b9f |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0064.prompt.md`, branch `feature/evidence-upload` @ `a678f81` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `91568b12fcbd11f6eb6e51690d916171c84a1dbe456fa05c3cb8955ecde033c2` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — not a merge blocker**  
   **File:** `modules/Evidence/src/Actions/EvidenceUploadService.php:72-78`

   If `writeStream()` partially creates an object and then throws, the cleanup block is not entered because cleanup only surrounds the database transaction. This leaves an orphaned quarantine object with no asset row.

   **Scenario:** SeaweedFS accepts part of a multipart upload, then the request fails. The service returns an error, but `quarantine/<ulid>` remains indefinitely.

   **Fix:** Wrap the object write and transaction in one cleanup boundary, or explicitly delete the generated key when `writeStream()` throws. Add failure-injection coverage for partial/write exceptions.

2. **Medium — not a merge blocker**  
   **File:** `modules/Evidence/src/Actions/EvidenceUploadService.php:95-100`

   Cleanup failures are only sent to `report($cleanup)` and are not otherwise tracked. The system can accumulate orphaned quarantine objects without an operational reconciliation mechanism.

   **Scenario:** Database persistence fails and SeaweedFS deletion also fails. The asset is absent, but the object remains and no durable custody or cleanup record exists.

   **Fix:** Emit a structured, rate-limited cleanup-failure log/metric containing only the generated quarantine key or ULID, and document/add a reconciliation job that identifies quarantine objects without corresponding asset rows.

3. **Low — not a merge blocker**  
   **File:** `modules/Evidence/database/migrations/2026_11_18_000100_create_evidence_assets_table.php:39-44`

   The database state constraint permits an `available` asset to retain a non-null `quarantine_key`. That is not an immediate content-disclosure issue because no download route exists yet, but it permits stale quarantine copies after P1-32b promotion.

   **Scenario:** P1-32b marks an asset available and copies it to the evidence bucket but forgets to clear the quarantine key. The row remains internally inconsistent and quarantine cleanup cannot reliably determine whether the object is still needed.

   **Fix:** Require `quarantine_key IS NULL` for `available`, and add a migration/test covering the transition expected by P1-32b.

4. **Low — not a merge blocker**  
   **File:** `modules/Evidence/src/Actions/EvidenceUploadService.php:154`

   `preg_replace()` can return `null` for malformed UTF-8, and the result is assigned to the title without an explicit fallback. The eventual database failure is handled, but the user receives a generic failure rather than validation feedback.

   **Scenario:** A request bypasses normal UTF-8 normalization and submits an invalid UTF-8 title. The upload object is written, then the asset insert fails and cleanup is attempted.

   **Fix:** Validate UTF-8 explicitly or use a safe fallback and return a field validation error before writing the object.

The MIME allowlist, extension matching, filename separation from storage keys, source URL non-fetching behavior, upload limits, private S3 driver, quarantine bucket separation, classification ceiling, 404 visibility behavior, `no_delete`/`append_only` grants, and dependency pins are otherwise consistent with the stated P1-32a design. This dependency assessment is from knowledge of the pinned Flysystem/AWS SDK versions; `vendor/` is not present, so I did not inspect vendor implementations.

## Missing tests

- Simulate `writeStream()` throwing after a partial object write and assert cleanup is attempted.
- Simulate quarantine deletion failure and assert structured logging/monitoring behavior.
- Assert database rejection of `status = available` with a non-null `quarantine_key`.
- Assert an available asset has its quarantine key cleared during the planned P1-32b transition.
- Test malformed UTF-8 in filename, title, description, and source URL.
- Test direct service invocation using a suspended/inactive actor, if permission evaluation does not already deny such actors.
- Test MIME polyglots for each accepted binary format, especially PDF/HTML and Office ZIP content.

## Recommendation

**Yes after fixes.** The branch does not appear less safe than `main` and does not currently break the central access-control or untrusted-upload guarantees, but the orphan-cleanup handling should be fixed before merge if the project wants quarantine storage to remain operationally bounded.

## What I did not check

- I did not run tests, static analysis, builds, migrations, containers, or SeaweedFS.
- I did not inspect `.env`, `.env.*` files other than examples, `.secrets/`, or vendor code.
- I did not validate the reported composer audit, smoke-test, or CI results independently.
- P1-32b scan/promotion behavior, ClamAV integration, content-addressed blobs, object lock, and download authorization are not implemented on this branch and were not reviewed beyond their schema compatibility.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-03)

Recommendation "yes after fixes"; all four findings fixed in the next commit. No credential file was opened (Codex's log).

| Finding | Disposition |
|---|---|
| 1. Medium: a write that fails part-way leaves an object | **Fixed.** One cleanup boundary now covers the write and the records: any failure from opening the upload onwards deletes the generated key (deleting a key that was never written is harmless). Test `test_a_write_that_fails_part_way_leaves_nothing_behind` (a disk whose write throws must receive the delete). |
| 2. Medium: a failed cleanup is not tracked | **Fixed, and the sweep is planned.** A failed delete logs `evidence.quarantine_orphan` with the generated key and the error class only (no file name, title or credentials), besides `report()`. Test `test_an_object_that_cannot_be_removed_is_logged_by_its_key_only`. P1-38's acceptance criteria now include a quarantine sweep for objects without an asset. A rate limit is not needed: at most one warning per failed upload. |
| 3. Low: an available asset may keep its quarantine key | **Fixed.** `evidence_assets_state_check` now requires `quarantine_key IS NULL` when `available`, so P1-32b must clear it on promotion; tested. |
| 4. Low: malformed UTF-8 | **Fixed.** Title, description and source URL are checked with `mb_check_encoding` before anything is stored (validation errors); a malformed file name cleans to `upload`, which has no accepted extension, and is refused. Tested. |
| Missing tests | Added: the write failure, the cleanup failure, the available-with-key CHECK, malformed text and file names, a suspended uploader (refused), a plain ZIP named `.docx` (refused). Deferred to P1-32b: the transition that clears the quarantine key. Not added: PDF/HTML polyglots, because the detected type of such a file is PDF and it is accepted as one by design; it is never rendered inline (P1-34 serves downloads as attachments with the type forced from the detected MIME, docs/03) and it is scanned in P1-32b. |
