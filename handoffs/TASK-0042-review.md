# TASK-0042 — Codex review of P1-23b (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (03:01:13–03:02:35 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19a34-344b-71c1-92df-7329243c3294 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0042.prompt.md`, branch `feature/p1-23b-channels-retailers-tags` @ `33c6dc9` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `0266bae4ee77842ec884e098ccb56f357c44be1f1e23125438c1876da2750deb` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — Tag catalog is disclosed to every competitor viewer**

   **File:** `modules/Registry/src/Http/Controllers/CompetitorController.php:77,105,198-202`

   `tagOptions()` returns every tag to all users who can view competitors, including users without `competitor.manage`. The UI hides the selector, but the complete tag taxonomy/name catalog remains in the Inertia response. This can disclose internal classification vocabulary or planned tags unrelated to visible competitors.

   **Scenario:** A Sales/Field user opens any visible competitor profile and inspects the page payload; they receive all tags, including unused or strategically sensitive tags.

   **Safe fix:** Return `tagOptions` only when the viewer has `competitor.manage`; otherwise return `[]`. Add a feature test asserting non-managers receive no tag options while still seeing tags attached to competitors they may view.

2. **Low — No authorization boundary exists for direct application-account SQL tagging**

   **File:** `modules/Registry/database/migrations/2026_11_12_000400_create_tags_tables.php:39-51`

   The generated-column foreign key guarantees that a tag link references an existing competitor, but it cannot enforce `AccessScope`. Any code path using the application account with direct `INSERT INTO taggables` can tag a hidden competitor without `CompetitorTagService`.

   **Scenario:** A future import, console command, or compromised internal code executes a direct insert for a competitor outside the actor’s grants. The database accepts it because visibility is not represented in the FK.

   **Safe fix:** Keep all user-controlled writes behind the service and add a repository/static-analysis rule forbidding direct `taggables` writes outside the service. If direct SQL writers are required later, give them a dedicated command/service with explicit actor context and visibility checks.

## Missing tests

- Non-manager competitor viewers receive no global `tagOptions` payload.
- A user cannot tag or untag a competitor outside their `AccessScope`, including restricted and multi-scope cases.
- Concurrent currency minor-unit update versus market create/update, using separate database connections.
- Concurrent tag removal versus tag assignment.
- Direct database attempts to link nonexistent competitors, delete in-use tags, and use unsupported taggable types.
- Website rendering tests for `http`, `https`, `javascript:`, mixed-case schemes, and malformed values.
- Grant convergence from an existing database where `currencies` was previously `read_only`.
- Empty `read_only` grant class remains fail-closed and receives no app write privileges.

## Recommendation

**Yes after fixes.** The database constraints and service-level authorization largely satisfy the brief, but the global tag catalog disclosure should be corrected before merge.

## What I did not check

- I did not run tests, static analysis, migrations, frontend builds, or database commands.
- I did not inspect runtime MySQL 8.4 behavior beyond the migration and repository tests; generated-column foreign-key behavior should remain covered by the project’s database CI.
- I did not review unrelated files outside the branch diff.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding | Disposition |
|---|---|---|
| 1 | Medium: every competitor viewer receives the whole tag catalog | **Fixed.** The list filter offers only tags carried by competitors the viewer can see, for readers and managers alike. The profile sends the catalog only to `competitor.manage` holders, and readers still see the competitor's own tags. Test `test_tag_options_reveal_only_what_the_viewer_may_see_or_apply` covers a used tag, a tag carried only by a hidden competitor, and an unused tag. |
| 2 | Low: direct application-account SQL could tag a hidden competitor | **Fixed as suggested.** A new architecture rule in `tests/Architecture/CodeRulesTest.php` lists the only files that may use `taggables`: `CompetitorTagService` (the only writer), the competitor controller (reads tags of visible competitors) and `Tag::inUse()`. A new reader or writer must be added there after review. The database cannot know the actor, so the visibility check stays in the service. |

**Missing tests:**

| Suggested test | Status |
|---|---|
| Non-manager tag options | Added (#1). |
| Tagging outside `AccessScope` | Already covered by `test_hidden_competitors_are_neither_tagged_nor_listed`: 404 for both tagging and untagging, and the list excludes hidden competitors. |
| Direct database attempts | Already covered by `test_the_database_refuses_invalid_tags_and_links` (missing competitor, unsupported type) and `test_a_tag_in_use_is_kept_by_the_service_and_the_database` (foreign key on removal). |
| Website schemes | Covered by `Retailers.test.ts` (`https`, `javascript:`) and `ChannelRetailerTest` (`javascript:`, `ftp:` refused by the service; `javascript:` by the CHECK). The rendering check is case-insensitive (`/^https?:\/\//i`), as on the competitor page. |
| Empty `read_only` class | Already covered by the probe-table test in `DatabaseAccountsTest`. |
| Grant convergence from an earlier class | Not a separate test. `TableGrants::apply` grants the target privileges and revokes all others on every run, and `test_every_existing_no_delete_table_rejects_delete_and_truncate` now covers `currencies` after the migrations in CI. |
| Parallel-connection races (currency decimal places against markets; tag removal against tagging) | Deferred to P1-25 with the other concurrency tests, as before. |
