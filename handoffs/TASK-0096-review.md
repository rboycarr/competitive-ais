# TASK-0096 — Codex review of P1-50 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b5c5-db2f-77a2-bc0e-2be81668c038 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0096.prompt.md`, branch `feature/audit-anchor` @ `6c7e795` |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `355b06799c1f10aac323367e366325b2a367694ae46ef7594e2c7a82ac3fef8d` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

### 1. High — forged supplementary checkpoint can be signed

**File:** `modules/Platform/src/Audit/AuditAnchor.php:103-145`, `:186-220`

`reanchor()` accepts any additional rows whose hashes are internally consistent and whose count exceeds the previous checkpoint. A database attacker can insert a fabricated audit row, compute a valid `row_hash`, then insert a revision-2 checkpoint or invoke `audit:reanchor`. The auditor will sign it because it cannot distinguish a genuine late commit from a forged row.

**Scenario:** A migrator inserts a fabricated event into a closed range, computes its hash, creates a supplementary checkpoint, and causes `anchorPending()` to export it. The resulting signed manifest legitimizes the forged event.

**Fix:** Require an independently verifiable late-commit condition, such as an immutable transaction/import record or trusted database commit metadata. At minimum, require `reanchor()` to compare the newly added IDs against a stored, immutable leaf-ID boundary and record explicit operator authorization outside the database. Do not let `anchorPending()` sign supplementary checkpoints merely because they have valid hashes and a larger count; it should only sign revisions created by the controlled reanchor workflow.

### 2. High — deleted `audit_anchors` rows are not detected

**File:** `modules/Platform/src/Audit/AuditVerifier.php:76-95`

Verification treats the object-store manifest as authoritative but does not require a corresponding `audit_anchors` row. Deleting an anchor row from the database leaves verification clean as long as the locked manifest remains in the bucket.

**Scenario:** A database administrator deletes the `audit_anchors` row for a valid manifest. `AuditVerifier` still finds and verifies the manifest, and reports no issue.

**Fix:** For every valid manifest, require exactly one matching `audit_anchors` row and validate `checkpoint_id`, `object_key`, `key_id`, `signature`, and `manifest_sha256`. Report missing or mismatched anchor metadata as critical. Also report anchor rows that have no corresponding valid object.

### 3. Medium — fake checkpoints can remain undetected until the stale window

**File:** `modules/Platform/src/Audit/AuditVerifier.php:80-91`

A database attacker can add a fake checkpoint that is not represented by a manifest. It is only reported as `unanchored` after the stale threshold, and the severity is high rather than critical. Depending on its range, it may also create confusing checkpoint/manifest state without an immediate integrity alarm.

**Fix:** Treat any checkpoint not represented by a valid manifest as critical once its creation predates the current verification snapshot, except for an explicitly documented short export retry window. Add structural checks ensuring checkpoints form one contiguous append-only sequence and cannot overlap or introduce unexplained generations/revisions.

### 4. Medium — verifier depends on the current private signing seed

**File:** `modules/Platform/src/Audit/AuditVerifier.php:48-50`, `modules/Platform/src/Audit/AuditSigner.php:25-27`

Verification calls `keyId()`, which derives the current public key from the private seed. Therefore, verification fails if the current seed is unavailable—even when trusted historical public keys are configured and all manifests are valid.

**Scenario:** During key rotation or disaster recovery, the current seed is temporarily unavailable but the trusted public keys and manifests remain intact. `audit:verify` refuses to verify rather than checking the manifests with public keys.

**Fix:** Configure the verifier with an explicit current public key plus historical trusted public keys. Keep private-seed access limited to signing operations. Verification should not require private-key material.

### 5. Medium — auditor outage is not externally observable

**File:** `modules/Platform/src/Console/RunAuditor.php:50-61`, `compose.yaml` auditor healthcheck

`anchor_stale` is generated only when the auditor itself runs. If the auditor crashes or is down, no process performs verification and no application-level alert is emitted. The container healthcheck only checks command registration, not liveness or recent successful checkpoint/verification timestamps.

**Scenario:** The auditor stops after its last successful run. No new checkpoint or verification occurs, so `anchor_stale` is never generated.

**Fix:** Add an external monitor or supervisory health endpoint based on a persisted “last successful checkpoint” and “last successful verification” timestamp. Alert independently when either exceeds the permitted interval.

### 6. Low — manifest validation permits semantically invalid signed values

**File:** `modules/Platform/src/Audit/AuditVerifier.php:197-205`

Parsing validates JSON types and key order, but not semantic constraints such as:

- `from >= 1`
- `to >= from`
- `count >= 0`
- `revision >= 1`
- `root` being exactly 64 lowercase hex characters
- `key_id` being exactly 16 lowercase hex characters
- canonical UTC timestamp formats

Only a holder of the signing seed can create such a manifest, so this is not the primary T21 bypass, but it weakens defensive validation and future key/parser safety.

**Fix:** Validate all numeric, hexadecimal, timestamp, and range invariants before signature acceptance and before using values for range selection.

## Missing tests

- Delete a valid `audit_anchors` row and assert a critical verification issue.
- Modify `audit_anchors.object_key`, `signature`, `key_id`, or `manifest_sha256` and assert a critical mismatch.
- Insert a forged valid-hash audit event plus a forged supplementary checkpoint, then verify that it cannot be signed automatically.
- Insert a fake checkpoint with no manifest and verify the intended severity and timing.
- Verify old manifests using trusted public keys when the current private seed is unavailable.
- Test auditor-down detection through health timestamps or an external watchdog.
- Test malformed but correctly signed manifests with invalid ranges, roots, key IDs, and timestamps.
- Test duplicate object versions after export succeeds but the anchor-row insert fails, including retry behavior and cleanup expectations.
- Test concurrent `audit:checkpoint`, `audit:work`, and `audit:reanchor` against the same range using real MySQL and the real object-store adapter.

## Answers to the review focus questions

1. **T21:** Ordinary edits to signed rows, checkpoints, or manifests are detected. However, forged valid-hash supplementary checkpoints can be signed, and deleted anchor metadata is not detected. The design is therefore not sufficient against a database writer with the ability to create rows/checkpoints.

2. **Signing before checking:** `anchorPending()` correctly rechecks row hashes, count, and root before signing normal checkpoints, including pre-P1-50 checkpoints. The `reanchor()` guard is not strong enough: “valid hashes plus more rows” does not prove a genuine late commit without an independent source of truth.

3. **Manifest trust:** Parsing is reasonably strict about object shape, manifest key order, JSON types, signature trust, generation, and object name. It should add semantic range/hex/timestamp validation. Re-encoding differences in the outer object do not invalidate a genuine manifest because the signature covers the canonical manifest only.

4. **Key isolation:** The reviewed Compose and CI configuration appears to restrict the signing seed to `auditor`, the anchor key to `auditor` and `seaweedfs`, and the admin key to `seaweedfs` and `seaweedfs-init`. The CI check is useful, but it should also assert that no service receives the signing or anchor secrets through mounts, inherited environment files, or runtime-generated configuration.

5. **Failure modes:** Export-before-database-insert is retryable because signing is deterministic, but it can create duplicate locked object versions and orphaned retention records. Concurrent runs are mostly protected by unique constraints, though concurrency tests should cover the complete workflow. Store failure fails closed. Auditor failure is not independently detected.

6. **Transaction monitor:** Measuring at commit is acceptable as monitoring, provided request/job termination limits are demonstrably below the lag window. The monitor appears to handle nested transactions by tracking the outermost level, but savepoint behavior and multiple connection instances need real integration tests. It is an alert, not a hard transaction cap.

7. **Migration:** The unique-key transition, self-reference, and CHECK constraint are coherent. The rollback refusal for supplementary checkpoints is intentional, but it means the normal rollback path is not universally reversible; the documented recovery procedure must be treated as a release requirement. The test teardown workaround should not substitute for a migration rollback test containing real supplementary data.

## Recommendation

**No — not safe to merge yet.**

The forged-supplementary-checkpoint path is a material violation of T21, and deletion of `audit_anchors` metadata is currently invisible to verification. These should be fixed and covered by adversarial tests before merge.

## What I did not check

- I did not execute tests, migrations, containers, CI scripts, or object-store checks.
- I did not open `.env`, non-example `.env.*` files, or anything under `.secrets/`.
- I did not inspect the full repository history or perform a secrets scan.
- I did not independently validate SeaweedFS behavior against a live instance.
- I did not inspect unrelated modules or the full deployment/runtime environment outside the files requested.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log). Recommendation "no"; all six findings addressed:

1. **High, forged supplement signed: fixed.** `anchorPending` signs only first revisions; supplementary revisions are signed only by `audit:reanchor`, which now requires the late IDs (`--late=`) and checks that the range *without* them matches the latest **signed manifest in the bucket** (count and Merkle root), not the database. A forged revision-2 row stays unsigned and is reported `unsigned_supplement` (critical). The operator's naming of the late rows is the remaining trust; it is audited (`late_ids`, reason). Tests: forged supplement never signed; wrong or unnamed late rows refused.
2. **High, deleted anchor rows undetected: fixed.** Verification compares each manifest's export record (object key, key ID, signature): missing or different → `anchor_record_mismatch` (high: the trail is intact, its bookkeeping was changed). A re-export of identical bytes no longer writes a new version; it records the anchor again with a warning log. Test added.
3. **Medium, fake checkpoints: fixed.** A first revision is signed only if it continues the signed ranges (from = last signed `to` + 1), so an overlapping inserted checkpoint stops the run; unsigned supplements are critical at once. Unexported first revisions stay `unanchored` (high) after the 30-minute export window. Test added.
4. **Medium, verifier needs the seed: fixed.** `AuditSigner::trustedKeys` works from public keys alone; verification needs only them. Test added.
5. **Medium, auditor outage not observable: fixed.** `audit:freshness` runs in the ordinary scheduler (no key) every 15 minutes, reads only the database, and logs critically when rows wait past the lag plus stale window. Test added; docs/10 §5.
6. **Low, semantic manifest checks: fixed.** Ranges, count, revision, generation, hex root and key ID, and timestamp formats are checked before the signature. Test added.

Not changed: concurrency of `audit:checkpoint`/`audit:work`/`audit:reanchor` stays covered by unique keys (no new test against the real store); the teardown in `AuditAnchorTest` removes supplementary rows because `DatabaseMigrations` rolls back, and the guard itself is the intended behaviour (docs/02).
