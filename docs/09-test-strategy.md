# 09 — Test Strategy

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Claude Code (acceptance criteria and test runs); Codex (adversarial test proposals) |
| Related | `skills/09_TESTING_DEVOPS_SKILLS.md`, `docs/07` §5 |

## 1. Test layers

| Layer | Tool | Scope | Runs |
|---|---|---|---|
| Static | Pint (format), Larastan (level 8 target), ESLint + vue-tsc, gitleaks | all code | every push |
| Architecture | Pest `arch()` | module boundaries; no `env()` outside config; no `dd`/`dump`; controllers use Form Requests; models use `HasPublicUlid` where routed | every push |
| Unit | Pest | value objects, AccessScope predicate, state-machine transitions, normalization (P2) | every push |
| Feature | Pest + MySQL 8.4 (not SQLite: CHECK constraints, JSON, and grants must behave like production) | HTTP routes, policies, Actions, jobs, events | every push |
| Authorization matrix | Pest dataset | role × clearance × grant × classification × endpoint → expected 200/403/404 | every push |
| DB grants | Pest, connecting as `cais_app` | UPDATE/DELETE on append-only tables fails | every push |
| Migrations | CI script | migrate → rollback → migrate on an empty DB; seeders run | every push |
| Queue / idempotency | Pest with `Queue::fake` + real Redis in CI | retries, uniqueness, duplicate dispatch, outbox replays | every push |
| Frontend unit | Vitest + Vue Test Utils | components, composables, form validation | every push |
| E2E | Playwright | critical journeys (J1, J6), keyboard paths, axe accessibility | PR to `main` |
| API contract | OpenAPI validation | `/api/v1` responses match the schema | every push |
| Ingestion [P3] | pytest + fixtures (saved HTML/RSS/PDF) | parsers, dedupe, SSRF suite, envelope signing | every push touching `workers/` |
| AI eval [P4] | eval runner (artisan command) | `docs/05` §7 suites | nightly + before model/prompt change |
| Voice [P5] | intent fixtures, confirmation E2E | `docs/06` acceptance | PR |
| Load | k6 | p95 targets (`docs/00` §7) on staging | before release |
| Security | composer/npm audit, ZAP baseline, external pen test | | release gates |
| Backup restore | scripted drill | restore into a scratch environment, run smoke tests | monthly + before release |

## 2. Test data
- Factories and seeders produce **synthetic** data only: fictional competitors (e.g., "Acme Snacks Corp"), fictional products, and invented prices clearly marked as demo data.
- No real company facts, personal data, secrets, or scraped content in fixtures.
- Collection parser fixtures [P3] are hand-written or come from sources whose terms allow reuse; their provenance is recorded in the fixture README.

## 3. Phase 1 critical tests (must exist before the exit demo)
1. Authorization matrix for every Phase 1 route, including hidden-record 404s.
2. MFA enforcement for privileged roles; invitation expiry and reuse.
3. SoD: author cannot review, reviewer cannot publish their own authored item, source proposer cannot approve.
4. State machine: each transition positive and denied; submit blocked without supporting evidence; version bump invalidates prior approval.
5. Evidence: EICAR rejected; MIME spoof rejected; duplicate upload dedupes the blob and keeps both assets; download requires visibility; custody + audit rows written.
6. Audit: every sensitive action writes exactly one audit row in the same transaction (rollback leaves none); checkpoint verification detects tampering.
7. DB grants test for append-only tables.
8. XSS payloads in titles and summaries render inert.
9. Playwright: the J1 + J6 journey end-to-end, plus axe with zero serious/critical violations on core pages.

**Added after Codex review TASK-0001:**
10. Evidence authorization matrix: assets scoped by declaration and by inheritance, unscoped assets, all-of semantics, hidden-evidence rows in provenance, downloads, previews, Inertia props, API resources, audit views.
11. Grants: typed-FK and CHECK enforcement at the database level (invalid scope combinations rejected by MySQL, not only by validation).
12. SoD: reviewer tries to publish; unassigned reviewer tries to decide; reassignment is audited.
13. Edit after approval: publish fails until the current version is approved; concurrent edit and publish race.
14. Uniqueness under concurrency: duplicate root regions, own brands, root categories; overlapping relationship intervals including open-ended ones (parallel requests).
15. Evidence races: duplicate uploads with racing scan jobs, one infected copy, one retried job; shared-blob retention as max and hold as OR; deletion job refuses a referenced or held blob.
16. Source policy at capture: the asset keeps its policy version after the source policy changes or the source is suspended.
17. Evidence withdrawn or integrity failure flags citing approved and published items for re-review.
18. Audit checkpoints: concurrent transactions, rollback gaps, a late commit inside a closed range (`late_commit` reported), repeated checkpoint runs (idempotent), retention deletion of `audit_event_network` leaving verification intact, and verification against a tampered database using the exported signed manifest.
19. Route inventory: every read, download, preview, export, broadcast channel, and API route invokes the authorization service.
20. MySQL 8.4 behavior: every CHECK, generated-key UNIQUE, FK, collation, and UTC assumption has a test.
21. Restore drill includes MinIO object versions, object-lock metadata, DB grants, and an evidence hash sample.

**Database identity in tests** (TASK-0001 #14): feature tests run with the real `cais_app` credentials so missing grants fail in CI. Migrations in CI run as `cais_migrator`.

**Phase 4/5 tests (designed now):** local AI endpoint rejects remote, rebinding, redirecting, and unapproved hosts; pending actions reject cross-user, session-mismatch, replayed, expired, changed-argument, revoked-permission, and concurrent confirmations.

## 4. Conventions
- Tests live with their module (`modules/<M>/tests`). Shared helpers live in `tests/Support` (e.g., `actingAsRole()`, `withGrant()`, `assertAudited()`).
- Each bug fix starts with a failing test.
- Flaky tests are quarantined with an issue reference within one day and are never silently retried.
- Coverage is tracked, not gamed. Target: ≥ 85% lines on Platform, Identity, Evidence, and Intelligence Actions and Policies.

## 5. Reporting
Every task report (CLAUDE.md §12) lists the actual commands run and their results. Checks that were not run are named with the reason.

## Acceptance criteria
- The CI pipeline runs all "every push" layers green on `main` before the Phase 1 exit demo.
