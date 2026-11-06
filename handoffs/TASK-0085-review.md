# TASK-0085 — Codex review of P1-41 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-06 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1ae4d-c5d8-7381-8f52-6247799db7af |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0085.prompt.md`, branch `feature/claim-evidence-links` @ `c976bfc` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `04b8fa2c8527740c2ecc22af0bad24c31f1a8a38ef72bd6bd2a02123cd863160` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No critical, high, medium, or low findings identified.

The reviewed implementation consistently:

- Applies `evidence.view` and `EvidenceAsset::seenBy` through `EloquentEvidenceLinking`.
- Hides locator, excerpt, title, ULID, and asset details for unseen evidence.
- Handles hidden-source names as `source.hidden`.
- Locks the intelligence item before the evidence asset.
- Performs duplicate checks under the item lock.
- Raises the item version for successful link/unlink operations.
- Writes audit and custody events inside the same transaction.
- Prevents deletion of cited evidence through the restrictive foreign key.
- Uses escaped title-search wildcards.
- Renders locator and excerpt as Vue text interpolation, not raw HTML.

## Answers to the review focus questions

1. Authorization and leakage

No leakage path was found in the reviewed page, picker, audit payload, errors, or unlink flow. `evidence.view` is enforced centrally in `EloquentEvidenceLinking::seen()`. Unlinking a hidden asset returns the same validation-style “does not cite” response as a nonexistent citation.

2. Concurrency

The locking order is correct: item row first, then evidence asset shared lock. Duplicate detection occurs while holding the item lock, and the database unique constraint provides a second integrity barrier. Link changes require the expected version and editable item state.

3. Integrity

The audit event and custody event are written within the same transaction as the link mutation. `mutable` is appropriate for the current workflow because links are intentionally added and removed before later immutable version hashing in P1-43.

An excerpt-to-asset-content check is not required for this story. Evidence may be binary, OCR may be unavailable or imperfect, and the excerpt is an analyst-supplied locator aid rather than a verified extraction. P1-43 or a later provenance/review feature could add optional verification metadata.

4. Tests

`ClaimEvidenceLinkTest` covers authorization, visibility, hidden sources, validation, duplicate relations, limits, XSS-safe rendering, audit/custody records, version changes, and hidden-asset unlinking.

`IntelligenceRaceTest` covers genuinely separate forked processes, item/asset lock ordering, concurrent moves and links, migration rollback refusal, and restrictive evidence deletion.

Missing useful follow-up tests:

- A failed custody-event insert must roll back the link, audit event, and version.
- A failed audit insert must likewise roll back the link and custody event.
- Concurrent linking of 20 distinct assets should never exceed the per-claim limit.
- Unicode and multibyte boundary tests for locator/excerpt lengths.
- Direct HTTP tests for malformed or lowercase/uppercase ULIDs across link and unlink routes.
- Verification that withdrawn or rejected evidence cannot be cited after a race with an attempted link.
- A browser-level test confirming a malicious excerpt remains text and cannot create DOM markup.

## Recommendation

Yes — safe to merge into `main`, subject to the follow-up tests above being added in the normal next test-hardening cycle.

## What I did not check

- I did not run PHPUnit, Vitest, static analysis, migrations, or any application command.
- I did not inspect `.env`, other credential files, or `.secrets/`.
- I did not review unrelated modules outside the requested files and their directly relevant tests.
- I did not independently verify the test results reported in `TASK-0085.md`.
- I did not inspect the full evidence-page implementation or every possible evidence-download route; those were outside the requested review scope.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-06)

No credential file was opened (Codex's log). Recommendation "yes"; no findings. The suggested follow-up tests:

| Suggested test | Disposition |
|---|---|
| A failed custody-event insert rolls back the link, audit event and version | **Added:** `ClaimEvidenceLinkTest::test_a_failed_custody_record_leaves_no_link_audit_or_version_change` (the custody write fails after the link and its audit event: nothing remains, the version is unchanged). |
| A failed audit insert rolls back the link and custody event | Covered by the same mechanism: the audit event and the custody event are written in the one `inItem` transaction, and `AuditLogger::record` refuses to run outside a transaction; the custody-failure test proves that transaction's rollback. |
| Unicode and multibyte boundaries for locator and excerpt | **Added:** `test_limits_count_characters_and_identifiers_are_checked` (255 four-byte characters accepted in the locator, 256 refused; 2,000 two-byte characters in the excerpt accepted, 2,001 refused). |
| Malformed and lower/upper-case ULIDs over HTTP, link and unlink | **Added** in the same test (malformed, empty and out-of-alphabet evidence IDs refused as `evidence` on both routes; a malformed claim ID refused as `claim`; lower-case claim and evidence IDs accepted for unlink). |
| Concurrent linking of distinct assets never exceeds the per-claim limit | Not added: every link is checked and written under the item's row lock with the version read, so at most one change per version goes through (`IntelligenceRaceTest` proves one winner per version); the count check runs under the same lock. |
| Withdrawn or rejected evidence cannot be cited after a race | Deferred to the evidence withdrawal story: no withdrawal path exists yet. Linking takes a share lock on the asset and requires `available`; rejection (the scan) and withdrawal lock the asset row for update. |
| Browser-level test that a malicious excerpt stays text | Deferred to the end-to-end suite (no Playwright yet, docs/09); `Intelligence.test.ts` mounts the page with a `<script>` excerpt and asserts it renders as text with no element. |
