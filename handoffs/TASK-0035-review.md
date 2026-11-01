# TASK-0035 — Codex verification of the TASK-0034 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (20:10:34–20:11:47 UTC; 2026-11-02 local) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a198bc-3d73-7772-a06f-a31f41af705b |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0035.prompt.md`, branch `feature/p1-21-aliases-relationships` @ `1425e38` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `c592482db3780424442d4ee37b69eb5b1f14a8b6c51d80b65548a596c4a17806` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0034 items

### #1 — Alias CHECK normal form

**Complete and correct.**

- **Severity:** Previously medium; resolved.
- **File:** `modules/Registry/database/migrations/2026_11_08_000100_create_competitor_aliases_table.php:28–35`
- The CHECK now:
  - rejects empty and ASCII-space-padded values;
  - rejects repeated ASCII spaces;
  - removes ASCII spaces before testing;
  - rejects remaining whitespace, Unicode separators, and control characters using `[\s\p{Z}\p{Cc}]`.

This matches `CompetitorAliasService::normalize()` at `modules/Registry/.../CompetitorAliasService.php:79–81`: all such characters are collapsed to ASCII spaces, then trimmed. The SQL now rejects values that the service would normalize differently.

The added direct-insert cases in `CompetitorAliasTest.php:96–110` are meaningful and would fail against the prior CHECK for non-breaking spaces, Unicode separators, and similar characters.

### #2 — Relationship deletion policy

**Disposition is documented but not complete until the Product Owner decides.**

- **Severity:** Low.
- **Files:** `docs/02-database-erd.md:189`; relationship grant configuration remains in `config/database.php:205–206`.
- The branch consistently documents relationships as `mutable`, with deletion treated as an audited correction and `valid_to` used for genuinely ended relationships.
- No technical defect remains if that is the approved policy.

**Failure scenario if the PO rejects this policy:** an analyst deletes a historically valid relationship rather than setting `valid_to`; the relationship disappears from queryable history, leaving only its audit event.

The branch should not be considered fully merge-ready until the PO explicitly accepts `mutable` or requests the `no_delete`/retraction design.

The stale-removal tests in `CompetitorAliasTest.php:137–146` and `CompetitorRelationshipTest.php:214–222` are meaningful: they exercise the service with an already-deleted model and verify no second audit event is created.

## 2. New problems introduced

No new application or security defect was identified in the fix.

One low-severity process/documentation issue exists:

- **Severity:** Low.
- **File:** `docs/12-claude-codex-handoffs.md:69`
- The TASK-0034 row says it was “Verified in TASK-0035” before this verification review has been completed. This is premature ledger state and could mislead release tracking, but it does not affect runtime behavior.

## 3. Recommendation

**Yes after fixes** — specifically, after the Product Owner explicitly decides whether relationship records remain `mutable`. The alias fix and stale-removal tests are satisfactory.

## 4. What I did not check

- I did not execute tests, static analysis, migrations, CI, or application code.
- I did not directly validate MySQL 8.4 ICU regex behavior or collation behavior against a live database.
- I did not review unrelated files outside the supplied guidance and changed-file scope.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes", conditional only on the PO's decision on relationship deletion.

| Item | Decision | Rationale |
|---|---|---|
| TASK-0034 #1 | **Confirmed fixed** | No further action. |
| TASK-0034 #2 | **PO decision** | Put to the PO with the merge request: keep `competitor_relationships` `mutable` (audited corrections; recommended) or move it to `no_delete` with a retraction flow before merging. |
| Ledger written ahead of the verification (Low) | **Resolved** | The TASK-0034 row named TASK-0035 as its verification before it ran; it now has run, and the TASK-0035 row records its actual result. |
