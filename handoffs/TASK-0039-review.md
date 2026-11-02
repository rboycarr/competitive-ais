# TASK-0039 — Codex review of P1-22b (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (00:00:48–00:02:01 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1998f-03bf-72e1-8826-320680604014 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0039.prompt.md`, branch `feature/p1-22b-products` @ `ef447d9` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `7dc29f92857d53545d773ac478468d41c5c20a39bc4679ccce33d920bb34b50d` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No verified findings.

I found the implementation consistent with the brief’s review focus:

- Product visibility uses `Product::scopeVisibleTo()` and `ProductAccess::allows()` with both classification and competitor visibility.
- Owner filters, form options, competitor pages, product details, and variants are visibility-filtered.
- Product/brand owner consistency is enforced by both service checks and the composite foreign key.
- Product and variant writes require `product.manage`; variants are checked against their parent product.
- Classification changes, audit classification, no-op audit behavior, and removal guards are implemented as specified.
- Vue output uses escaped interpolation; no unsafe HTML rendering was introduced.

## Missing tests

The branch includes substantial coverage, but these additional adversarial cases should be added or explicitly confirmed:

- A user who can see a product’s classification but cannot see its competitor receives 404 for product detail and cannot observe it through list filters, competitor pages, or variant routes.
- A hidden competitor ULID supplied to the product `owner` filter does not reveal whether products exist.
- A product linked to a brand from another competitor is rejected at both service and database levels.
- Concurrent brand deletion and product creation cannot leave a product with a deleted brand.
- Concurrent category deletion and product creation cannot leave a product with a deleted category.
- A variant ULID belonging to another product returns 404 and cannot be updated through the selected product route.
- Product and variant route model binding does not expose records before service-level visibility checks.
- Classification downgrade requires `intelligence.publish`; classification upgrade beyond the actor’s visibility is rejected.
- Audit rows for product changes use the higher of product and competitor classification and contain no caller-controlled context.
- No audit event is emitted for product, variant, brand, or category no-op updates.
- Migration rollback/reapply verifies the composite brand-owner foreign key and its supporting unique index.

## Recommendation

Yes — safe to merge into `main`, based on the read-only review.

This recommendation is conditional on the task-brief evidence being accurate; I did not independently execute the reported test suites.

## Unresolved assumptions / questions

- The product/category taxonomy is treated as globally visible. This matches the current model, but the Product Owner should confirm that category names are intentionally not classification- or competitor-scoped.
- Product variants inherit product visibility rather than carrying independent classification. This appears intentional and should remain documented as the invariant.

## What I did not check

- I did not run tests, static analysis, migrations, builds, or database checks.
- I did not inspect runtime behavior, MySQL constraint compatibility, or concurrent transaction behavior.
- I did not verify the test results reported in `handoffs/TASK-0039.md`.
- I did not review unrelated files outside the changed branch diff.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

No findings; recommendation "yes".

| Missing test | Decision | Rationale |
|---|---|---|
| Product visible by classification, competitor hidden | **Covered** | `test_visibility_follows_the_matrix_and_the_competitor`: products of the restricted competitor B against 72 users (`visibleTo` and `ProductAccess`), 404 on detail, update and variant routes, absent from the list. A deliberate mutation dropping the competitor condition was caught. |
| Hidden competitor ULID in the `owner` filter | **Added** | In the matrix test: filtering by B returns an empty list, the same as for an unknown ULID (`test_the_list_filters_by_owner_status_and_category`). |
| Brand of another competitor (service and database) | **Covered** | `test_the_brand_belongs_to_the_same_owner` (another competitor's, ours on a competitor's product, an unknown brand; the composite key refuses a direct update). |
| Concurrent brand/category removal and product creation | **Defer** | P1-25. Both sides lock the brand/category row (shared lock on write, exclusive on removal), and the foreign keys are the backstop. |
| A variant through another product's URL | **Covered** | `VariantTest::test_variants_follow_their_products_visibility_and_url`. |
| Route binding before visibility | **Covered** | Every hidden-record route returns 404 in the tests above; bound models are only used after `ProductAccess`. |
| Classification up and down | **Covered** | `test_classification_rules_match_competitors`. |
| Audit classification and context | **Covered** | `ProductTest` and `VariantTest` assert classification and exact context. |
| No audit for no-ops | **Covered** | Product, variant (here), brand and category (P1-22a tests). |
| Migration rollback and reapply | **Covered** | `scripts/ci/backend.sh` runs the migration round-trip in CI. |

| Question | Answer |
|---|---|
| Categories globally visible? | Yes, by design: the category tree is unclassified shared taxonomy (docs/02 has no classification for it); put to the PO with the merge request. |
| Variants inherit product visibility? | Yes, documented in docs/02 (P1-22b note) and the model. |
