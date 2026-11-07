# TASK-0100 — Codex verification of the TASK-0099 fixes (P1-51) (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-08 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b81b-580c-7643-8494-f86fdf3b5c93 |
| Mode | `codex exec -s read-only -C <repo>`, commit `613b1b7` on `feature/restore-drill` (lean loop: the single verification round) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `387ea27fccba842752de341c698b6f31a90a8f0b04f8c7b35dda4d32b34c51e1` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0099 findings

1. **Resolved.** `backup.sh:38–42` writes the listing to a file under `set -e`; listing failure aborts before completion, and the `ERR` trap removes `.partial`. The CI dead-endpoint case covers this.

2. **Not resolved.** `restore-drill.sh:38–40` checks only volumes. `docker compose ... down -v --remove-orphans` at lines 49 and 56 can still remove unrelated containers or networks belonging to another `cais-drill` project if they have no volumes, or if their volumes are external/unlabeled. The ownership check must cover all resources that cleanup can remove, or use a unique run-scoped project name and avoid `--remove-orphans`.

3. **Resolved for the stated finding.** `restore.sh:91–102` compares per-key version counts, delete-marker counts, and latest-entry type after replay. The CI seeds and checks a latest delete marker. It does not prove original version IDs, which are necessarily remapped.

4. **Resolved.** `restore.sh:62–63` rejects manifest buckets outside `EVIDENCE_BUCKET` and `AUDIT_ANCHOR_BUCKET` before object-store operations.

5. **Resolved.** CI probes successful listing of `evidence` and failed write, delete, and access to unauthorized buckets. The negative coverage is materially improved.

6. **Resolved for the current single evidence disk.** The remap SQL now requires `storage_disk = 'evidence'` and counts other-disk references as missing. The untested multi-disk scenario remains an architectural limitation, not an unresolved regression in this fix.

7. **Resolved for concurrent execution.** `flock` serializes drills. Log tamper evidence remains out of scope as explicitly accepted by the disposition.

## 2. New medium-or-higher findings

### Medium — identical configured bucket names make every nonempty restore fail

File: `docker/backup/restore.sh:97–101`

The restored-history check loops over both configured buckets independently. If `EVIDENCE_BUCKET` and `AUDIT_ANCHOR_BUCKET` are configured to the same bucket, the same object history is listed twice, so `have` contains doubled counts and cannot equal `want`.

Failure scenario: An environment sets both bucket variables to `evidence`—whether intentionally or through a configuration mistake. Object replay succeeds, but the restore aborts with “the restored version history differs from the manifest.”

Suggested fix: Validate that the two configured bucket names are distinct before restoring, or deduplicate the bucket list before the history scan. Add a configuration test covering equal bucket names.

## 3. Recommendation

**No.** The branch should not merge until the `cais-drill` cleanup ownership check also protects containers and networks, not only labeled volumes. I did not execute tests, Docker commands, restores, migrations, or network operations.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-08)

Codex's log shows it opened no credential file and nothing under `backups/`. Six of the seven TASK-0099 findings were verified as resolved. Both open points were fixed in the next commit:

- **#2, not resolved: cleanup could still remove another project's containers or networks.** Fixed with Codex's own suggestion. Every run uses a new project name, `cais-drill-<UTC time>-<pid>`, and refuses to start if any container, volume or network already carries it. Cleanup is `down -v` without `--remove-orphans`. The volume labels are gone. CI now creates another project's container, network and volume under the old fixed name, runs a full drill beside them, and checks that all three survive and no drill project is left behind. `restore.sh` accepts only `cais-drill-<run>` names for a drill.
- **New medium: identical bucket settings.** `backup.sh` and `restore.sh` now refuse when `EVIDENCE_BUCKET` equals `AUDIT_ANCHOR_BUCKET`.

Also fixed: CI's first drill run failed because Docker created `backups/` as root on the runner. `init-env.sh` now creates it as the user, mode 700. The CI test script reports a failure as a redacted annotation.

Under the lean loop (docs/12 §1), there is no further Codex round. These last fixes are verified by CI and the local drill, and the PO is told so.
