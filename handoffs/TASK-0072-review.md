# TASK-0072 — Codex review of P1-33 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a950-9413-7cc1-ab92-777b5edb9471 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0072.prompt.md`, branch `feature/evidence-dedupe-provenance` @ `a5b253f` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `2de3c60a68628086dd2b3765af4315747049c0e08ef3130d9c7093e47003964b` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No merge-blocking findings.

No non-blocking defects found in the changed code or reviewed Evidence paths. `EvidenceScanService` assigns the shared blob only to the individual asset being scanned; custody, audit, provenance, and classification remain asset-specific. `EvidenceAsset::seenBy()` applies visibility to each asset, not to its shared blob.

## P1-33 acceptance

Met by `main` plus this branch:

- Same bytes produce one `evidence_blobs` row and one stored object.
- Two assets retain distinct titles, sources, capture times, uploaders, and classifications.
- Each asset retains its own upload and scan custody events.
- Audit records are queried by each asset’s `subject_ulid`.
- Internal visibility does not widen through blob sharing.

Still undelivered by design:

- UI indication that a file/blob is shared; deferred to P1-34.
- Evidence download authorization and custody recording; P1-34.

## Missing tests

- A concurrent two-upload/two-scan test through the service boundary, although the underlying deduplication race coverage was delivered in P1-32b.
- An HTTP-level visibility test for shared assets; current coverage tests `EvidenceAsset::seenBy()` directly.
- Download-path isolation, appropriately deferred to P1-34.

## Recommendation

Yes — safe to merge into `main`.

## What I did not check

- I did not run tests, Pint, Larastan, CI, migrations, or application commands.
- I did not inspect or exercise the future download/detail UI paths because they are outside this branch and P1-34.
- I did not open `.env`, credential files, or `.secrets/`.
- I did not inspect the untracked `.claude/` directory.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-05)

Recommendation "yes"; no findings, so nothing to fix. On the missing tests Codex listed:
- **Concurrent uploads and scans of the same bytes:** the race on the blob is covered by P1-32b (the per-hash named lock, `test_a_scan_waits_for_another_storing_the_same_bytes`, and the atomic insert-or-reload). Further race and failure-injection tests are the scope of P1-38.
- **HTTP-level visibility of shared assets:** the evidence list and detail routes both go through `EvidenceAsset::seenBy()` (`EvidenceController`), which this test exercises directly, and their HTTP visibility is tested in `EvidenceUploadTest`. The download route arrives with P1-34, with its denial test.
- **Download isolation:** P1-34.
