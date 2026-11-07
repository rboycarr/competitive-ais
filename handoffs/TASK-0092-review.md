# TASK-0092 — Codex review of P1-45 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-07 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1b2bc-f588-74c2-9334-1dfa5bfd2f21 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0092.prompt.md`, branch `feature/provenance-view` @ `196e728` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `b53255fe202d46617ef61da6061c84a5619ff4aa6bced9eeaa1483eb096647cb` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

### High — hidden evidence identifiers leak through audit context

- File: `modules/Intelligence/src/Http/Controllers/ProvenanceController.php:135–142`
- Scenario: An auditor can view the intelligence item and has `audit.view`, but lacks access to one cited evidence asset. The audit trail includes an `intelligence.evidence_linked` event whose `context` contains `evidence_ulid`. The JSON rendered by the provenance page therefore reveals the hidden asset’s identifier, violating the requirement that hidden evidence is never described.
- Fix: Do not expose raw audit contexts from this view. Redact evidence-related identifiers unless the viewer can see the corresponding asset, or define a provenance-specific audit-context transformer that removes `evidence_ulid` and related hidden-record identifiers.

### Medium — source lookup causes an N+1 query pattern

- File: `modules/Intelligence/src/Http/Controllers/ProvenanceController.php:112–113, 154–163`
- Scenario: A provenance page contains 100 visible citations. `citation()` invokes `SourceLookup::recorded()` independently for each citation, causing one or more database queries per citation.
- Fix: Batch-load visible source/policy summaries through a contract method accepting multiple `(sourceId, policyId)` pairs, or cache lookup results during the request keyed by source and policy IDs.

### Medium — linked-item lookup can add repeated queries

- File: `modules/Intelligence/src/Http/Controllers/ProvenanceController.php:70–73, 127–134`
- Scenario: Retraction and supersession links each call `IntelligenceItem::seenBy(...)->first()`. In larger workflows or if the relationship model expands, this becomes repeated authorization queries and can be difficult to optimize.
- Fix: Resolve linked item IDs in one authorized query and map the results, or add an authorized lookup contract for linked intelligence summaries.

### Low — audit row cap has no explicit stable pagination/cursor

- File: `modules/Intelligence/src/Http/Controllers/ProvenanceController.php:58–60`
- Scenario: The page shows the first 200 rows ordered by numeric ID, while the “More events” link sends the user to the general audit search. Concurrent inserts or future ordering changes can make the boundary unstable or confusing.
- Fix: Either document that the provenance panel is a bounded snapshot, or provide a cursor/“load older” link based on the last event ID and the same subject/access filter.

## Answers to the review focus questions

### 1. Leakage

Most direct evidence, source, linked-item, and custody data is filtered correctly:

- The item is checked with `ensureSeenBy`.
- Evidence is filtered through `EvidenceAsset::seenBy`.
- Hidden evidence is counted per claim.
- Sources are returned as placeholders when `SourceLookup::recorded()` denies visibility.
- Replacement and superseding items are resolved through `IntelligenceItem::seenBy`.
- Audit rows use `AuditEvent::visibleTo`.

However, raw audit `context` is not filtered. Audit contexts can contain `evidence_ulid`, so an auditor without access to the evidence can still learn its identifier. This is a high-severity provenance leakage issue.

The custody actor names follow the existing evidence-page behavior and are only collected for evidence traces already visible to the viewer. I found no additional hidden-evidence title, hash, source name, or item title leak in the controller/UI path.

### 2. Hash correctness

The comparison itself is correct:

- It computes the current content hash.
- It selects an approval for the current item version.
- It compares the approval hash with the current hash using `hash_equals`.

A later content change causes `matchesApproval` to become false, as covered by the feature test. The implementation does not appear to treat an approval from an older item version as current.

The remaining assumption is that multiple approvals for the same version are ordered reliably by `decided_at` and `id`; the query is ordered accordingly.

### 3. Module boundaries

The dependency cycle is acceptable under ADR-0001:

- Evidence depends on the Intelligence contract `IntelligenceCitations`.
- Intelligence depends on Evidence contracts `EvidenceProvenance` and `EvidenceTrace`.
- No implementation class crosses the boundary.
- The architecture test explicitly permits other modules’ `Contracts` and `Events`.

This is a reasonable contract-only cycle, although the architecture test should ideally include an explicit regression assertion documenting that mutual contract dependencies are intentional.

### 4. Performance

The main concern is `SourceLookup::recorded()` per citation. The evidence trace query itself is batched, and custody events are eager-loaded, which is good.

The audit query is bounded to 201 rows, so it will not load an unbounded audit history. User summaries are also batched through one directory call. Linked-item resolution and per-citation source lookup remain the main avoidable query costs.

### 5. Tests

The tests cover:

- visible and hidden evidence;
- hidden sources;
- hidden intelligence items;
- hash changes after approval;
- audit visibility and audit-view auditing;
- frontend escaping and rendering;
- evidence-page citation counts.

They do not cover the high-risk audit-context leakage described above.

## Missing tests

- Auditor with `audit.view` but without `evidence.view` requests provenance for an item citing hidden evidence; assert the audit JSON contains no hidden evidence ULID or other hidden-record identifier.
- Auditor with access to the item but not its source; assert audit and provenance output contain neither source ID nor source name.
- Multiple approvals for the same item version; assert the latest approval is selected.
- Approval for an older version plus current-version content; assert the older approval is not used.
- A current-version approval whose hash differs from the recomputed content hash; assert `matchesApproval` is false.
- Large provenance payload with many citations; assert query count does not grow linearly per citation, especially after batching source lookups.
- Audit trail with exactly 200 and 201 matching rows; assert `more` is respectively false and true.
- Malformed or missing audit context; assert safe empty-object rendering.
- Missing claim relation/data-integrity edge case in `EloquentIntelligenceCitations`; ensure orphaned links cannot create a bogus hidden count.

## Unresolved assumptions / questions for the Product Owner

- Should auditors be allowed to see raw audit contexts containing identifiers for records outside their evidence scope? The current security model suggests no.
- Is exposing reviewer, assignee, uploader, and custody actor names to every viewer of a visible item intentional, or should names also be classified/redacted?
- Is the 200-row audit panel intended as a snapshot only, or should it support deterministic continuation/pagination?
- Should provenance viewing itself be audited for all viewers, or only when the audit panel is included as this branch implements?

## Recommendation

**Yes after fixes.**

The branch is structurally sound and the hash, authorization, hidden-count, and contract-boundary designs are largely correct. It should not merge until audit-context redaction is added and covered by a negative authorization test. The per-citation source lookup should also be addressed or explicitly accepted as a performance follow-up.

## What I did not check

- I did not run PHPUnit, Pest, Vitest, ESLint, Larastan, or type checks. The brief reports prior results, but I did not independently execute them.
- I did not inspect `.env`, other local environment files, or `.secrets/`.
- I did not inspect database migrations and indexes beyond the code paths required for this review.
- I did not perform browser-based or production-scale performance testing.
- I did not make any file changes.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-07)

No credential file was opened (Codex's log quotes CLAUDE.md). Recommendation "yes after fixes".

| # | Finding | Disposition |
|---|---|---|
| 1 | High: audit contexts in the provenance view can name hidden evidence | **Fixed.** Every `*_ulid` value in an audit context is shown only when it names something the viewer sees on this page: the item, its claims, its reviews, evidence the viewer sees, or a linked item the viewer sees; any other is replaced by `hidden` (`reviewer_ulid`, a user, is kept: reviewer names are shown with the reviews). Test: an auditor whose grants do not cover the evidence's other competitor sees `intelligence.evidence_linked` with `evidence_ulid` hidden and the asset's ULID nowhere in the page. The admin audit log (P1-16) still shows raw contexts to `audit.view` holders: raised with the PO |
| 2 | Medium: a source lookup per citation | **Fixed.** Looked up once per source and policy version on the page |
| 3 | Medium: linked items looked up one by one | **Fixed.** The replaced, superseding and replacement items are resolved in one `seenBy` query |
| 4 | Low: the audit panel is capped without pagination | **Accepted, documented**: the panel shows the item's first 200 audit events as a bounded snapshot, then links to the audit log filtered by the item (which pages). Test: 200 events show no "more", 201 do |
| — | Suggested tests | Added: audit-context redaction (above), the 200/201 boundary. Already there: a hidden source (placeholder, never named), content changed after approval. Not applicable yet: several approvals of one version (an approved item is not reviewed again until P1-48 adds re-review); orphaned links (FK CASCADE on the claim, RESTRICT on the evidence) |
| — | PO questions | Raised in the P1-45 report: the admin audit log's raw contexts; names of reviewers, uploaders and custody actors shown to every viewer of a visible item (as on the item and evidence pages since P1-41/P1-32a) |
