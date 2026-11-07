# TASK-0097 — Codex verification of the TASK-0096 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b5da-ff71-7d22-ab1f-d76fa670b13e |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0097.prompt.md`, branch `feature/audit-anchor` @ `13b89f7` |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `0eeb7182d111dca35e2402e28c6087d1c064db967ad9dea62315c3455586f363` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0096 findings

1. **Forged supplementary checkpoint — resolved.**  
   `anchorPending()` only exports revision 1 checkpoints (`AuditAnchor.php:76-80`), while `reanchor()` requires explicitly named late IDs and compares all other rows to the signed object-store manifest (`AuditAnchor.php:122-139`). A database-only forged supplement is therefore left unsigned and reported as critical. The bucket manifest closes the database-checkpoint forgery gap, assuming the bucket and operator workflow are trusted.

2. **Deleted `audit_anchors` rows — not fully resolved.**  
   Verification now reports missing or changed export records (`AuditVerifier.php:93-100`), but it does not compare `manifest_sha256`. More importantly, if an earlier anchor record is deleted after a later checkpoint was anchored, `anchorPending()` may reject the earlier checkpoint because `signedTo` reflects the later range (`AuditAnchor.php:84-90`), preventing automatic re-recording.

3. **Fake checkpoints — resolved.**  
   Revision-1 checkpoints must begin at the next ID after the highest anchored checkpoint. Overlapping or out-of-order checkpoints are rejected by `anchorPending()`.

4. **Verifier dependence on private seed — resolved.**  
   `trustedKeys()` can operate solely from configured public keys (`AuditSigner.php:61-76`), and verification no longer requires the signing seed.

5. **Auditor outage observability — correctly dispositioned/resolved.**  
   `audit:freshness` runs from the ordinary scheduler without signing credentials and checks overdue rows against the last exported checkpoint (`CheckAuditFreshness.php:22-40`). It detects stopped anchoring, though it does not itself verify manifest integrity.

6. **Semantic manifest validation — resolved.**  
   Range, count, revision, generation, hexadecimal, and timestamp constraints are checked before signature acceptance (`AuditVerifier.php:249-260`).

## 2. Findings

### High — anchor repair can be blocked after a later checkpoint is anchored

**File:** `modules/Platform/src/Audit/AuditAnchor.php:84-90`

`anchorPending()` calculates `signedTo` from all currently recorded anchors. If anchor metadata for an earlier checkpoint is deleted after a later checkpoint has been exported, the earlier checkpoint remains pending but its `from_audit_id` is no longer `signedTo + 1`; the method throws before it can re-record the valid existing manifest.

**Scenario:**

1. Export ranges 1–10 and 11–20.
2. Delete the `audit_anchors` row for range 1–10.
3. Run the auditor.
4. The pending 1–10 checkpoint is examined while `signedTo = 20`; the command throws instead of restoring the missing anchor record.

**Safe fix:**  
When a pending checkpoint already has a valid, matching locked manifest in the bucket, restore its anchor record independently of contiguity checks. Alternatively, make the contiguity check apply only to genuinely new revision-1 exports, not repair of an existing manifest.

### Medium — `manifest_sha256` is not verified

**File:** `modules/Platform/src/Audit/AuditVerifier.php:98-100`

The verifier compares `object_key`, `key_id`, and `signature`, but not `audit_anchors.manifest_sha256`, despite the prior finding specifically requiring that field to be validated.

**Scenario:** An attacker changes only `manifest_sha256` in the database. Verification reports no mismatch even though the export bookkeeping is inconsistent.

**Safe fix:**  
Compute the manifest object's SHA-256 and require the anchor row’s `manifest_sha256` to equal it. Include the field in the comparison and mismatch detail.

## 3. Answers to TASK-0097 questions

1. **Re-signing without naming every late row:** No, not through the implemented reanchor path. Every late row must be named, and the non-late rows must reproduce the signed bucket manifest’s count and Merkle root. This closes the prior database-only forgery path, although operator honesty remains a governance assumption.

2. **Contiguity:** It correctly blocks overlapping or out-of-order new checkpoints and does not inherently block O45 back-signing when ranges are processed in order. However, deleted earlier export records can cause a legitimate repair to fail once later ranges are anchored, as described above.

3. **Re-recording safety:** Reusing identical bytes at the same object key is safe and avoids creating another locked version. A different object at the key is passed to `LockedObjects::write`, which should refuse the overwrite. The missing `manifest_sha256` comparison remains unsafe bookkeeping.

4. **Freshness command:** It discloses only operational metadata—overdue row count and last signed ID—and requires ordinary database read access to audit tables. It does not need signing credentials. The scheduler account must have access to `audit_events`, `audit_checkpoints`, and `audit_anchors`; that should be explicitly confirmed in grants/configuration.

## 4. Recommendation

**Yes after fixes.**

Before merging, fix the earlier-anchor repair path and validate `manifest_sha256`. Add tests for both cases, especially deleting the first anchor after a later range has already been anchored. No tests, migrations, containers, or live services were executed by me.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log). TASK-0096 #1, #3, #4, #5, #6 resolved; #2 not fully resolved, with two new findings, both fixed with tests:

1. **High, re-recording blocked after later ranges were signed: fixed.** The contiguity check moved into `AuditAnchor::export` and applies only to a new first-revision write; identical bytes already locked at the key are recorded again wherever the range lies. Test: `test_an_earlier_export_record_deleted_after_later_ranges_is_recorded_again`.
2. **Medium, `manifest_sha256` not compared: fixed.** Verification compares the export record's SHA-256 with the object's bytes as well. Test: the export-record test changes only `manifest_sha256`.

Answer 4 (grants): the scheduler uses the app account, which may read `audit_events`, `audit_checkpoints` and `audit_anchors` (append-only grant class, SELECT and INSERT; `DatabaseAccountsTest`); `audit:freshness` ran live in the php service.
