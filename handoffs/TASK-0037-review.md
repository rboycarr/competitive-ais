# TASK-0037 — Codex verification of the TASK-0036 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (22:14:46–22:15:34 UTC; 2026-11-02 local) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1992d-f1f0-7ad2-8106-94b39340ecb4 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0037.prompt.md`, branch `feature/p1-22a-brands-categories` @ `270af53` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `9632100ad6811b6110acc776f12fca28651de79cbef1c3d478c77efecf90acc2` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0036 disposition review

### #1 — Category parent/path integrity

**Complete and correct overall.**

The new `CategoryTree::inconsistencies()` compares each category with its actual parent row, including path and depth, and follows parent links for subtree discovery. Missing parents and cycles cannot silently pass this check. `CategoryService::move()` performs the check inside the transaction after the subtree update, so detected corruption rolls back. The verification command has correct success/failure exit codes and is scheduled daily.

Relevant files:

- `modules/Registry/src/Support/CategoryTree.php:25–58`
- `modules/Registry/src/Actions/CategoryService.php:95–133`
- `modules/Registry/src/Console/VerifyCategoryTree.php:14–25`
- `modules/Registry/src/RegistryServiceProvider.php:32–35`

The rejected trigger/self-referencing-cascade approach is reasonable for the stated MySQL/subtree-move design. The documentation now correctly limits what the row-level `CHECK`s guarantee.

### New issue — medium

**Path-based subtree update can modify unrelated corrupt rows that are outside the parent-linked subtree.**

File: `modules/Registry/src/Actions/CategoryService.php:119–123`

The move updates every row whose `path` begins with `$oldChildPath`, while `CategoryTree::inconsistencies($locked->id)` validates only rows reachable from the moved category through `parent_id`.

Failure scenario:

1. A corrupt direct database write creates an unrelated category whose `parent_id` points elsewhere but whose path begins with the moved category’s old path. This is permitted by the current row-level checks because they only require the declared parent ID to occur somewhere in the path.
2. A move updates that unrelated row’s path and depth.
3. The validator excludes it because it is not a descendant through `parent_id`.
4. The transaction commits with the unrelated row altered or newly inconsistent.

Suggested fix: either identify/update descendants using parent links, or have the move-time validation cover every row affected by the path update and reject any affected row whose parent relationship does not place it in the moved subtree. A regression test should create such an unrelated malformed row and verify the move rolls back.

### #2 — Concurrency tests

**Disposition is complete and reasonable for this branch.**

The brief explicitly assigns parallel-request testing to P1-25, and `docs/11-delivery-roadmap.md` records that scope. The existing locking and database uniqueness mechanisms remain untested under concurrency, but that is an acknowledged follow-up rather than an incomplete TASK-0036 fix.

## 2. Other new problems

The unrelated-row path-update issue above is the only material new correctness problem found in the changed implementation.

The verifier intentionally reports corruption rather than repairing it, which is appropriate. It is also inherently an in-memory full-table scan; that is acceptable for the current Phase 1 scale but should be revisited if category volume grows substantially.

## 3. Recommendation

**Yes after fixes.**

The original TASK-0036 findings are addressed, but the path-based move update should be hardened against corrupt rows outside the moved subtree before merging.

## 4. What I did not check

- I did not execute PHPUnit, frontend tests, static analysis, migrations, CI, or Artisan commands.
- I did not connect to or validate against a live MySQL 8.4 instance.
- I did not inspect every unchanged authorization or routing path.
- I did not perform parallel-request testing; the repository correctly defers that work to P1-25.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "yes after fixes": TASK-0036 #1 complete, #2 deferral accepted, one new medium issue.

| Item | Decision | Rationale |
|---|---|---|
| TASK-0036 #1 | **Confirmed fixed** | — |
| TASK-0036 #2 | **Deferral confirmed** | P1-25. |
| New: the path-prefix `UPDATE` can rewrite a corrupt row outside the parent-linked subtree (Medium) | **Fixed** | `CategoryTree::descendantIds()` finds the subtree through parent links once; the move computes the depth limit from, and rewrites, exactly those rows (`WHERE id IN (…)`), then checks them. A subtree that is already inconsistent is not moved at all: the move checks it first and refuses with a clear error (rolled back; `registry:verify-categories` reports the rows), so a corrupt descendant is never rewritten. Test `test_a_move_rewrites_only_its_own_subtree`: a corrupt row under another parent whose path starts like the moved subtree keeps its path and depth, the audit counts one subcategory, and the tree check still reports it. |
| Full-table scan in the check (note) | **Accepted** | Categories are a small admin-maintained taxonomy; revisit with volume. |
