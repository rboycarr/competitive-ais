# TASK-0099 — Codex review of P1-51 (encrypted backup and restore drill) (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-08 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b812-2fff-7d41-a11d-e2adfc8a2fa6 |
| Mode | `codex exec -s read-only -C <repo>`, diff `60468a3..ce1d5af` on `feature/restore-drill` (lean loop, docs/12 §1: no separate brief or prompt file) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `46188d5b17a1b18d7274055ed34c82b6dff6890c44a2558d08364a41204b95ae` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — backup listing failures are converted into an apparently successful backup**  
   File: `docker/backup/backup.sh:35–37`

   The object-version listing ends with `|| true`, so any `list-object-versions` failure, including pagination or S3 connectivity errors, is treated as an empty listing. The backup is then finalized with zero or incomplete object versions.

   Failure scenario: SeaweedFS returns a partial listing followed by an error. The command output is sorted and processed, the backup directory is renamed from `.partial`, and the drill may pass if the database has no corresponding evidence rows or the omitted objects are not referenced.

   Fix: Capture the AWS CLI exit status separately and fail the backup on any listing error. Validate that every expected bucket was listed successfully and that the manifest counts match `backup.info`.

2. **High — fixed `cais-drill` cleanup can remove an unrelated Compose project**  
   Files: `scripts/ops/restore-drill.sh:17–19, 45–48, 39`

   The script assumes that the Compose project named `cais-drill` belongs exclusively to this drill. Its initial `down -v --remove-orphans` and final cleanup can remove containers, networks, and volumes belonging to another operator’s project with the same name.

   Failure scenario: An unrelated local or shared project uses the `cais-drill` project name. Running the restore drill deletes that project’s volumes, potentially including data.

   Fix: Use a unique, run-scoped project name and record/validate an ownership marker before cleanup, or refuse to proceed if any pre-existing resources are present. Avoid `--remove-orphans` unless ownership is proven.

3. **High — restore replays delete markers without preserving their version identity or validating final object state**  
   File: `docker/backup/restore.sh:61–63`

   Delete markers are exported with their original version IDs, but restore uses an unversioned `delete-object`. This creates a new marker and does not verify that the restored version history and latest-marker state match the manifest.

   Failure scenario: A key has multiple versions followed by a delete marker. The drill creates a new marker but does not verify its position or identity; a restore can appear successful while producing a different visible object state or incomplete history.

   Fix: Explicitly model delete-marker restoration, capture the newly created marker version ID, maintain a remapping table for markers, and verify the restored version listing against the manifest’s expected ordering and latest flags.

4. **Medium — restore trusts arbitrary manifest bucket names and can write outside the approved restore buckets**  
   File: `docker/backup/restore.sh:55–79`

   The key and version ID are validated, but `bucket` is not constrained to the configured evidence and audit-anchor buckets. The restore uses the administrator S3 credentials, so a modified-but-valid backup can cause writes to another bucket.

   Failure scenario: A holder of the decryption key produces a valid age-encrypted manifest naming an unintended bucket. The restore service writes objects there using the admin key.

   Fix: Validate every manifest bucket against an explicit allowlist (`EVIDENCE_BUCKET`, `AUDIT_ANCHOR_BUCKET`) before any object-store operation. Prefer a restore-specific write credential limited to the drill buckets.

5. **Medium — the object-store backup key’s permissions are not tested as negative capabilities**  
   Files: `docker/seaweedfs/s3-identities.template.json:28–33`, `scripts/ci/restore-drill-test.sh`

   The configuration grants the intended read/list/lock-read actions, but CI does not prove that the key cannot write, delete, access other buckets, or read object data outside the two approved buckets.

   Failure scenario: A SeaweedFS action mapping or version upgrade interprets `Read`/`List` more broadly than expected. The backup key can mutate data or access `exports`/`evidence-quarantine`, contrary to O48.

   Fix: Add explicit CI probes using the backup credentials: successful reads/lists for both target buckets; failed writes, deletes, bucket listing, and reads from every other bucket.

6. **Medium — evidence version remapping does not include the storage disk/bucket identity**  
   File: `docker/backup/restore.sh:90–97`

   The remap joins only `storage_key` and the old version ID. It does not include `storage_disk`/bucket, even though the source rows contain storage identity.

   Failure scenario: Two storage backends or buckets contain the same key and version-ID value. A row can be remapped to the wrong restored object.

   Fix: Include the storage disk/bucket in the temporary mapping table and update join condition. Add a fixture with identical keys across two buckets/backends.

7. **Low — the restore-drill log is append-only but not protected against tampering or concurrent writers**  
   File: `scripts/ops/restore-drill.sh:33–40`

   The result log is written directly with `>>` and is not locked or integrity-protected.

   Failure scenario: Two drills run concurrently and interleave log records, or a local user edits the log to conceal a failed drill.

   Fix: Serialize drills with a lock and include a signed/hash-chained result or store results in an auditable system if the log is used as operational evidence.

## Missing tests

- S3 listing failure, pagination failure, and partial-listing failure must make `backup.sh` fail and leave only `.partial`.
- Backup with an omitted unreferenced object must fail validation rather than pass the drill.
- Delete-marker round-trip with multiple versions, latest marker state, and version-ID remapping.
- Manifest bucket outside the approved allowlist must be rejected.
- Evidence rows using duplicate storage keys across buckets/backends.
- Collision with an existing unrelated `cais-drill` Compose project must refuse to run without deleting anything.
- Negative permission tests for the backup key: write, delete, bucket listing, and access to `exports`/`evidence-quarantine`.
- Tampered `backup.info`, missing manifest, extra object ciphertext, duplicate manifest numbers, malformed fields, and zero-entry manifests.
- Concurrent restore-drill invocations and cleanup after failures at every step.
- Verify restored object listings, including delete markers, latest flags, and complete version counts—not only SHA-256 checks for data objects.

## Unresolved assumptions / questions

- Is `cais-drill` guaranteed to be exclusively reserved on every host, or must the script support arbitrary unique project names?
- Is recreating delete markers with new IDs acceptable under O48, or must the drill prove complete version-history equivalence?
- Should disaster recovery remain callable directly through the `restore` service, or must it require a separate explicitly protected production-only workflow?
- Is SeaweedFS’s action vocabulary formally documented and tested for the intended bucket-level restrictions?

## What I did not check

I did not execute tests, Docker Compose commands, migrations, restores, or network operations. I did not inspect `.env`, `.env.*` non-example files, `.secrets/`, or `backups/`. I reviewed the specified Git diff and readable tracked source/docs only.

## Recommendation

**No.** The branch should not merge until the listing-failure handling and `cais-drill` project ownership/cleanup protections are fixed and the negative permission and delete-marker tests are added.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-08)

Codex's log shows it opened no credential file and nothing under `backups/`. All seven findings were accepted and fixed in the commit after `ce1d5af`:

| # | Finding | Fix |
|---|---|---|
| 1 | A failed version listing could finalize a backup | The listing goes to a file under `set -e`; a failure stops the backup and the `ERR` trap removes `.partial`. CI points the backup at a dead endpoint and expects no complete backup. |
| 2 | Cleanup could remove an unrelated `cais-drill` project | Every drill volume carries the label `cais.restore-drill=1`. The drill refuses, removing nothing, when any volume under the project name lacks it. CI tests this with a foreign volume. |
| 3 | Delete markers were not checked after replay | After the replay, the restored listing per key (versions, markers, latest kind from `IsLatest`) must equal the manifest's. CI seeds an object whose latest entry is a delete marker. |
| 4 | The manifest's bucket names were not checked | Only `EVIDENCE_BUCKET` and `AUDIT_ANCHOR_BUCKET` are accepted, before any object-store call. |
| 5 | The backup key's limits were not tested | CI checks the backup key: it lists evidence, but cannot write, delete, or list `exports` or `evidence-quarantine`. |
| 6 | The remap ignored the storage disk | The remap joins on `storage_disk = 'evidence'` as well. Any other row with a version ID counts as missing, and the restore fails. |
| 7 | Concurrent drills and log integrity | Drills are serialised with `flock`. A tamper-proof log is out of scope: the log is an operator's record, and the audit trail is the integrity mechanism. |

Missing tests added: a failed listing, an extra object file, a delete-marker round trip, a foreign project volume, and the key's limits. Not added: a duplicate storage key across disks, which needs a second evidence disk the app does not have, and concurrent drills, which `flock` prevents.
