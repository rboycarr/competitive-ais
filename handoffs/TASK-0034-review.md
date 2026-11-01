# TASK-0034 — Codex review of P1-21 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (19:57:50–19:59:08 UTC; 2026-11-02 local) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a198b0-9562-71c3-9579-eeef64e7088c |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0034.prompt.md`, branch `feature/p1-21-aliases-relationships` @ `084ab97` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `476818997043e94dde5184cdd39cda141a28b2c64890ac0c47aad288959049b9` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — database alias CHECK does not fully enforce the service’s alias normal form**

   **File:** `modules/Registry/database/migrations/2026_11_08_000100_create_competitor_aliases_table.php:25–27`

   The service normalizes all Unicode whitespace/separator characters using `[\s\p{Z}\p{Cc}]`, but the database CHECK only rejects repeated ASCII spaces and POSIX control characters. A direct database write such as `Acmé Brands` containing a non-breaking space, or multiple Unicode separators, can bypass the stated normal form.

   **Scenario:** A privileged maintenance script inserts an alias directly with Unicode whitespace. The row passes the CHECK, while application-created aliases normalize to a different value, defeating the documented invariant and potentially creating lookup inconsistencies.

   **Safe fix:** Make the database constraint enforce the same canonical representation, or ensure all writes—including maintenance/import paths—must use the service. Add a migration-level test proving that tabs, non-breaking spaces, Unicode separators, and repeated whitespace are rejected or normalized consistently.

2. **Low — relationship deletion is allowed despite the ERD’s append-history rationale being unresolved**

   **Files:** `config/database.php:205–206`; `modules/Registry/routes/web.php:20–24`

   Relationships are classified as `mutable`, permitting DELETE. The brief explicitly asks whether relationships should instead be `no_delete`. Since corporate relationships are time-qualified facts and removals can erase historical assertions, allowing deletion may weaken provenance unless “remove” is strictly limited to erroneous records.

   **Scenario:** An analyst deletes a valid historical relationship rather than closing it with `valid_to`; the record and its historical state disappear, leaving only an audit event and no queryable relationship history.

   **Safe fix:** Confirm the Product Owner’s policy. If history must be preserved, move `competitor_relationships` to `no_delete`, replace deletion with an audited correction/retraction or required end-date update, and adjust the UI wording and tests. If deletion is intentionally permitted for data-entry mistakes, document that decision explicitly and retain the current grant.

## Missing tests

- Database-level alias normal-form tests for Unicode whitespace/separators and direct inserts.
- Authorization matrix coverage for every alias and relationship endpoint across:
  - visible competitor + hidden other competitor;
  - hidden route competitor;
  - insufficient clearance;
  - missing competitor/alias/relationship;
  - users with `competitor.manage` but no visibility.
- Relationship overlap tests for:
  - inclusive end boundaries (`valid_to = new valid_from`);
  - open-ended ranges;
  - reverse-direction non-distributor relationships;
  - both directions for `distributor`;
  - update excluding its own row;
  - concurrent inserts and updates using separate database connections;
  - deadlock retry exhaustion and successful retry.
- Tests confirming no audit event is written for no-op updates or already-removed rows.
- Tests confirming relationships cannot be deleted if the final policy is `no_delete`.
- Frontend tests for relationship payload mapping, especially parent/child direction, precision, notes, and hidden competitors.
- Frontend tests confirming alias, relationship names, notes, and error messages are rendered as text rather than HTML.

## Recommendation

**Yes after fixes.**

The branch’s service-level authorization, visibility filtering, overlap logic, audit classification, and frontend text interpolation are generally aligned with the stated guarantees. The alias CHECK mismatch should be resolved, and the deletion policy for relationships should be explicitly decided and reflected in the grant class and tests before merging.

## What I did not check

- I did not execute the backend or frontend test suites, static analysis, migrations, or CI commands, per the read-only review constraint and the instruction not to claim execution.
- I did not inspect runtime MySQL collation behavior directly.
- I did not review unrelated files outside the changed-file set except where required by the supplied guidance and task brief.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes".

| # | Finding | Decision | Rationale |
|---|---|---|---|
| 1 | Alias CHECK weaker than the service's normal form (Medium) | **Fixed** | The CHECK now removes ASCII spaces and refuses any remaining whitespace, separator or control character (`[\s\p{Z}\p{Cc}]`), besides trimmed and no double spaces. `CompetitorAliasTest` inserts directly a tab, a no-break space, a line separator, an ideographic space, a bell character and a lone no-break space; each is refused by the CHECK. The migration is not merged, so it was changed in place. |
| 2 | Relationships deletable (`mutable`) (Low) | **PO decision** | Implemented as documented in docs/02 (P1-21 note): removal is an audited correction for data-entry mistakes; a relationship that ended gets a `valid_to`, and the UI says so before removing. Put to the PO with the merge request: keep `mutable` (recommended) or move to `no_delete` with a retraction flow. |

| Missing test | Decision | Rationale |
|---|---|---|
| Alias normal form at the database level | **Added** | See #1. |
| No audit for already-removed rows | **Added** | Both tests remove a second time through a stale copy via the service; still exactly one audit event. No-op updates were already covered. |
| Endpoint × visibility cases | **Covered** | Hidden route competitor, hidden other competitor (and an unknown ULID: same message), a relationship reached through an uninvolved competitor, readers without `competitor.manage`, missing rows (404) and a restricted-grant holder are in `CompetitorAliasTest` and `CompetitorRelationshipTest`. Competitor visibility itself is matrix-tested in `CompetitorTest`. |
| Overlap cases | **Covered** | Inclusive end, open end, adjacent ranges, reverse direction, both directions for `distributor`, another type, another pair, edits excluding their own row: `test_ranges_for_the_same_pair_and_type_do_not_overlap`. |
| Concurrent writes and deadlock retries on separate connections | **Defer** | P1-25 (parallel-request tests for registry uniqueness and the locked overlap check). |
| Frontend payload mapping and text rendering | **Covered** | `Show.test.ts`: type and role mapping, the symmetric "partner" offered once, alias, competitor names and notes rendered as text. |
