# TASK-0036 — Codex review of P1-22a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (21:52:07–21:53:11 UTC; 2026-11-02 local) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19919-35eb-7852-9242-e976bdec6edf |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0036.prompt.md`, branch `feature/p1-22a-brands-categories` @ `3ae982d` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `c2e3cac053f55989a02a0e0c0303a8604ac13bf17e90cd445c34b00d10e708e7` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **Medium — category database constraint does not fully tie `parent_id` to `path`**

   **File:** `modules/Registry/database/migrations/2026_11_09_000200_create_product_categories_table.php:35–38`

   The parent check only requires that `path` ends with `/{parent_id}/` somewhere. It permits inconsistent rows such as:

   ```text
   parent_id = 5
   path      = /12/5/9/5/
   depth     = 3
   ```

   This satisfies the syntax, depth, and parent checks despite the category not being an immediate child of parent `5`. Any application path that writes directly through the mutable database account can create a tree that violates the stated integrity guarantee.

   **Safe fix:** enforce the immediate-parent relationship at the database boundary, using a database mechanism compatible with MySQL’s restrictions (for example, a carefully designed trigger or a schema representation that derives the path from parent data). At minimum, add an explicit integrity validator and test that rejects paths whose final ancestor is not the declared parent.

2. **Low — concurrency coverage is incomplete for the stated P1-25 guarantee**

   **Files:** `modules/Registry/tests/Feature/CategoryTest.php`, `modules/Registry/tests/Feature/BrandTest.php`

   The tests cover sequential duplicate inserts and moves, but not concurrent requests. The roadmap explicitly requires uniqueness under concurrency and the brief calls out concurrent creates and moves.

   **Safe fix:** add parallel-request tests for:

   - two simultaneous brands with the same owner/name;
   - two simultaneous categories under the same parent/name;
   - concurrent moves involving overlapping subtrees;
   - concurrent create-under-parent versus move/remove of that parent.

## Missing tests

- Inactive users cannot read or mutate brands/categories through direct HTTP requests or service calls.
- A super admin with `taxonomy.manage` but without `product.view` can access categories but cannot access the Brands page.
- Restricted competitor visibility is enforced for brand index data, competitor options, duplicate-name errors, route binding, rename, and deletion.
- Direct database writes with inconsistent `parent_id`, `path`, and `depth` are rejected.
- Concurrent brand/category uniqueness and tree mutation tests.
- Category move tests covering ASCII path conversion with multi-level prefixes and depth increases/decreases.
- Frontend tests asserting `parent: null` is sent for top-level create/move and that the category itself and all descendants are excluded from move options.

## Recommendation

**No.** The branch’s category constraint does not enforce the full stated parent/path integrity guarantee, and the required concurrency tests are absent.

## What I did not check

- I did not execute PHPUnit/Pest, frontend tests, static analysis, migrations, or CI scripts.
- I did not inspect unchanged shared authorization internals beyond the relevant model/service usage.
- I did not validate behavior against a live MySQL 8.4 instance.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "no", based on #1 and the concurrency tests.

| # | Finding | Decision | Rationale |
|---|---|---|---|
| 1 | Category CHECK ties only the last path segment to the parent (Medium) | **Fixed (whole-tree check), and the guarantee is now stated precisely** | A CHECK sees one row; full consistency needs the parent's row. A trigger (privileges and binary-log settings) or a self-referencing composite key with `ON UPDATE CASCADE` (refused by MySQL for self-referencing cascades) would not survive multi-row subtree moves. Instead: `Modules\Registry\Support\CategoryTree::inconsistencies()` compares every row with its parent (path = parent's path + parent ID, depth + 1; finding the subtree through parent links, not paths), which also rules out cycles. `CategoryService::move()` runs it on the moved subtree before committing and rolls back otherwise; `registry:verify-categories` checks the whole tree and is scheduled daily. Test: Codex's example shape (a row under "Chips" whose path repeats the parent's ID) passes the CHECKs, is reported by the check and the command, and blocks a move of its subtree. docs/02 now says what the CHECKs guarantee alone. |
| 2 | No parallel-request tests (Low) | **Defer to P1-25** | docs/11 P1-25 ("registry uniqueness holds under concurrency") is the story for parallel-request tests of the generated-key UNIQUE indexes (brands, categories) and the locked tree and overlap checks. The locks are in place now. |

| Missing test | Decision | Rationale |
|---|---|---|
| Inactive users | **Added** | `BrandTest`: a suspended analyst is signed out (redirect to `/login`) on reading and on writing. The middleware is shared by every route and tested in Identity. |
| Super admin: categories yes, brands no | **Covered** | `BrandTest` (403 on `/brands`) and `CategoryTest` (200 on `/admin/categories`). |
| Restricted competitor: index, options, errors, binding, rename, removal | **Covered** | `test_a_hidden_competitors_brands_are_invisible_and_unchangeable`; a duplicate-name error cannot arise for a hidden owner because the owner lookup fails first. |
| Inconsistent direct writes | **Added** | See #1. |
| Concurrency | **Defer** | See #2. |
| Multi-level moves, depth up and down, ASCII conversion | **Covered** | `test_moving_carries_the_subtree_and_never_builds_a_cycle` (three levels down by one, then up), `test_the_tree_has_at_most_six_levels`. |
| Frontend `parent: null` and move options | **Added / covered** | `Categories.test.ts` now also checks `parent: null` for a top-level create; the move options already exclude the category and its subtree. |
