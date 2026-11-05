# TASK-0073 — Codex review of P1-34 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a98a-d11d-79b3-94e3-fdda18e8cfd7 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0073.prompt.md`, branch `feature/evidence-download` @ `f011db7` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `836cba3a0ea2fac9afe7d6b282ee1ebf8af490896dab5d51eb6a2be97c5069c5` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No findings. No merge blockers identified.

The implementation consistently:

- Enforces `evidence.download` and asset visibility.
- Fails closed for unavailable or integrity-failed assets.
- Uses a transaction with an asset shared lock.
- Records custody and audit events before returning the URL.
- Limits expiry to 60 seconds.
- Uses the stored object key/version and attachment/octet-stream response overrides.
- Requires HTTPS outside local/testing environments.
- Uses POST plus CSRF protection and rate limiting.

## Acceptance and ADR-0007

P1-34 acceptance is met:

- Policy check: yes.
- Pre-signed URL valid for no more than 60 seconds: yes.
- `downloaded` custody event: yes.
- Audit event: yes.
- Denial coverage: yes, including missing permission, hidden assets, invalid states, failed audit recording, GET, and guest access.

ADR-0007’s attachment-only rule is met through signed `ResponseContentDisposition` and `ResponseContentType` options. The implementation also tests the S3 signing behavior and stored version binding.

Nothing material appears undelivered for this task.

## Missing tests

Recommended additional coverage, non-blocking:

- A malformed or missing `object_version_id` in an otherwise available blob, verifying the intended fail-closed behavior.
- A filename containing control characters, if database-level validation is later relaxed.
- A production-style proxy test confirming the configured download endpoint preserves path-style addressing and host signing.
- A concurrent status transition test using two database connections.

## Unresolved assumptions / questions

- The deployment must ensure `EVIDENCE_DOWNLOAD_ENDPOINT` is reachable by browsers and points only to the S3 API, not an administrative console.
- Production configuration must provide an HTTPS endpoint; the empty-value fallback is safe only when `AWS_ENDPOINT` itself is browser-reachable and HTTPS.
- The current implementation assumes the existing scan pipeline guarantees a valid stored object version for production evidence blobs.

## Recommendation

Yes, the branch is safe to merge into `main`.

## What I did not check

- I did not execute PHPUnit, frontend tests, static analysis, or the SeaweedFS probe; I relied only on the committed code, tests, documentation, and the test results recorded in the task brief.
- I did not inspect `.env`, other ignored environment files, or `.secrets/`, per repository instructions.
- I did not perform live storage, database, proxy, or browser verification.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "yes"; no findings. On the missing tests and assumptions:
- **Blob without `object_version_id` (missing test #1, assumption 3): fixed.** Without a version, the link would serve the object's latest version, not necessarily the stored one. Outside tests, `EvidenceDownloadService` now refuses such a blob, fail-closed (ADR-0007 requires the version). The fakes store no version, so tests are exempt. Test: `test_a_blob_without_its_stored_version_is_not_served_outside_tests`.
- **Control characters in file names (#2):** refused at upload (P1-32a) and by `evidence_assets_filename_check`. `HeaderUtils::makeDisposition` also throws on a name it cannot carry, so a link is never built from one.
- **Production proxy (#3) and endpoint assumptions (1, 2):** an operations concern, documented in `docs/10` ("Evidence downloads"). The local probe confirmed host signing with path-style addressing. A production check belongs to the P1-51 restore and deployment drill.
- **Concurrent status change (#4):** there is no withdraw or status-changing action yet. It arrives with withdrawal and P1-35. The shared lock is in place for it, and the race test belongs with that story or P1-38.
