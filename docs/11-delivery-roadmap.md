# 11 — Delivery Roadmap and Backlog

| Field | Value |
|---|---|
| Status | Phase 0 approved 2026-10-31. Docker setup done and dependency list approved 2026-10-31. **Sprint 1.0 complete** 2026-11-01 (P1-01 to P1-09); Sprint 1.1 started; O17 decided (SeaweedFS). |
| Owner | Product Owner (priorities, approvals); Claude Code (backlog maintenance) |
| Related | `skills/10_ROADMAP_SKILLS.md`, `competitive-ais.md` §14, §17 |

**Effort scale** (one engineer with AI assistance): **S** ≈ 1–2 days · **M** ≈ 3–5 days · **L** ≈ 1–2 weeks · **XL** > 2 weeks. These are relative estimates with stated assumptions, not date commitments.

## 1. Phase overview

| Phase | Goal | Exit criteria | Size |
|---|---|---|---|
| 0 Discovery | Architecture, ERD, threat model, backlog, protocol | Docs approved and committed; Codex review recorded *if the user runs it* | done → review |
| 1 Secure Foundation (MVP-A) | Identity, registries, sources (manual), evidence, intelligence + verification, audit, basic dashboard | J1 + J6 work end to end; unauthorized access blocked in tests; all six Phase 1 gates passed | **5 sprints (8–14 wk)**, revised after TASK-0001 #13 |
| 2 Intelligence Ops (MVP-B) | Prices, field, search, alerts, battlecards, reports, notifications | Observation → verification → alert/report on synthetic data; exports audited | 4–6 sprints |
| 3 Approved Collection | RSS/API collectors, snapshots, dedupe, source health | E2E collection from explicitly approved sources with traceability and rate limits | 3–4 sprints |
| 4 AI & RAG | Local AI gateway, hybrid retrieval, citations, tools, evals | Eval thresholds met; zero leakage and zero obeyed injections | 4–6 sprints (after spike) |
| 5 Voice & Mobility | PWA, push-to-talk, intents, confirmations, TTS | Voice cannot bypass authorization or confirmation | 3–4 sprints |
| 6 Enterprise | Connectors, graph, forecasting, realtime voice, scale | Each capability justified individually | per item |

## 2. Phase 1 backlog (MVP-A)

**Security gates** (TASK-0001 #13). Each gate must be demonstrable on its own before the next sprint's work merges. A schedule slip pushes the date; it never skips a gate.

| Gate | Passed when | Sprint |
|---|---|---|
| G1 Platform & DB integrity | Stack healthy; DB accounts and grants verified; migrate/rollback in CI; audit + outbox working | 1.0 |
| G2 Identity & authorization | MFA, RBAC, typed grants, `AccessScope` with the matrix harness green | 1.1 |
| G3 Canonical registries | Registry schema with NULL-safe uniqueness and overlap checks, concurrency tests green | 1.2 |
| G4 Evidence lifecycle | Quarantine → scan → blob, scopes, policy capture, race tests green | 1.3 |
| G5 Intelligence workflow | State machine with SoD and version-bound approval, re-review flagging | 1.4 |
| G6 Operational hardening & acceptance | Signed audit anchor, restore drill, accessibility, E2E exit journey | 1.5 |

### Sprint 1.0 — Foundation and walking skeleton

| ID | Story | Acceptance criteria | Size | Depends on |
|---|---|---|---|---|
| P1-01 | As the team, we have the Phase 0 docs, ADRs, and handoff protocol in the repo | `docs/00–12`, `docs/adr/0001–0010`, `AGENTS.md`, `handoffs/` exist and are cross-checked | M | — (**done** 2026-10-31) |
| P1-02 | As a developer, I can start the stack with one command | WSL2 + Docker installed (user); `compose.yaml` services healthy; app at :8080; no conflict with XAMPP | M | user setup, dependency approval (**done** 2026-10-31: `bash scripts/dev/setup.sh` once, then `docker compose up -d --wait`; all 10 services healthy; app, Vite and Mailpit reachable from Windows; ports on 127.0.0.1 only; object-store key policy 19/19; versions pinned in ADR-0003) |
| P1-03 | As a developer, the repo is versioned with protected `main` | `git init`; `.gitignore` excludes `.env`, `vendor`, `node_modules`, storage; remote + branch protection per O1 | S | O1 (**in progress** 2026-10-31: GitHub repo `rboycarr/competitive-ais`, made **public** by PO decision after the initial commit `26eb6df` was pushed to `main`; **done** 2026-11-01: ruleset (named "main" by the PO) imported by the PO from `.github/rulesets/protect-main.json` (`docs/10` §1.5); verified through the GitHub rules API: active on `main` with no deletion, no force-push, pull requests only (merge commits), and the `backend`, `frontend`, `secrets` checks required with the branch up to date. Because the repo is public, every commit must contain synthetic data only and pass a secret/personal-data scan before push) |
| P1-04 | As an architect, modules load automatically and boundaries are enforced | `modules/*` discovery; Pest arch tests fail on a forbidden cross-module import | M | P1-02 (**done** 2026-10-31: `ModulesServiceProvider` discovery; `ModulesPlatform` with base `ModuleServiceProvider`; Pest 5.3.4 arch tests; forbidden imports proven to fail with temporary modules; `docs/01` §3) |
| P1-05 | As an auditor, every sensitive action leaves a tamper-evident record | `audit_events`, `audit_checkpoints`, `AuditLogger`, checkpoint + verification jobs; grants test passes | M | P1-04 (**done** 2026-11-01: merged after Codex reviews TASK-0010/0011; signed manifests are P1-50) |
| P1-06 | As the platform, domain events reach consumers reliably | `outbox_events`, `OutboxPublisher`, `RelayOutboxJob` with idempotent handler test | S | P1-04 (**done** 2026-11-01: merged as pull request #1 after Codex reviews TASK-0012/0013) |
| P1-07 | As a developer, CI blocks bad merges | Pipeline: Pint, Larastan, Pest (MySQL 8.4), migrate/rollback, Vitest, build, audits, gitleaks | M | P1-03, O1 (**done** 2026-10-31: `.github/workflows/ci.yml` with backend, frontend, secrets jobs; CI run #2 green on `28ca5c7` (run #1 failed: script modes and container uid, fixed); required-checks branch protection pending (PO); ESLint/vue-tsc arrive with the Vue setup; `docs/10` §1.4) |
| P1-08 | As a user, the app has secure defaults | Security-headers middleware (CSP nonce), problem+json errors, request IDs, UTC config | S | P1-04 (**done** 2026-11-01: merged as pull request #2 after Codex reviews TASK-0014/0015) |
| P1-09 | As the platform, every DB connection runs with least privilege (TASK-0001 #14) | `cais_migrator`, `cais_app`, `cais_readonly`, `cais_retention` created by an init script (local, CI, staging); `db:apply-grants` command; feature tests connect as `cais_app`; tests prove UPDATE/DELETE fail on append-only tables and that every required INSERT succeeds; documented rollback | M | P1-02 (**done** 2026-10-31: accounts by init script; `db:apply-grants` + automatic run after migrations; 11 grant tests as `cais_app`/`cais_retention` on MySQL 8.4; rollback in `docs/02` §6; hardened after Codex reviews TASK-0003 to TASK-0009, merged 2026-11-01: exact account allowlist, table-by-table grant verification, 30 Pest tests) |

### Sprint 1.1 — Identity and access

| ID | Story | Acceptance criteria | Size |
|---|---|---|---|
| P1-10 | As an admin, I invite users; there is no self-registration | Invite email (Mailpit); token hashed; 72 h expiry; single use; audit `user.invited`/`user.activated` | M (**done** 2026-11-02: merged as pull request #4 after Codex reviews TASK-0017/0018) |
| P1-11 | As a user, I log in with password + TOTP MFA | Fortify; recovery codes; throttling; failed logins audited | M (**done** 2026-11-02: merged as pull request #5 after Codex reviews TASK-0019/0020) |
| P1-12 | As the security owner, privileged users cannot work without MFA | `EnsureMfa` blocks privileged permissions until enrolled (test per permission) | S (**done** 2026-11-02: merged as pull request #6 after Codex reviews TASK-0021/0022) |
| P1-13 | As an admin, I manage roles, permissions, and teams | Seeded roles (`docs/07`); role changes audited; cannot edit own roles | M (**done** 2026-11-01: merged as pull request #7 after Codex reviews TASK-0023/0024) |
| P1-14 | As an admin, I grant scoped access with expiry | `access_grants` CRUD (revoke, never delete); self-grant blocked; audited | M (**done** 2026-11-01: merged as pull request #8 after Codex review TASK-0025; scope `all` only until the registry exists, PO decision) |
| P1-15 | As every module, visibility is decided in one place | `AccessScope` (`allows`, `applyTo`); matrix harness in `tests/Support`; 404 for hidden records | L (**done** 2026-11-01: merged as pull request #9 after Codex review TASK-0026; matrix: 4 clearances × 2 statuses × 7 grant variants × 4 classifications × 4 scope shapes, through `allows()`, `visibleTo()` and HTTP) |
| P1-16 | As an auditor, I can search the audit trail | Read-only viewer; filters (actor, action, subject, date); `audit.view` only | M (**done** 2026-11-01: merged as pull request #10 after Codex reviews TASK-0027/0028) |
| P1-17 | As a user, I can see and revoke my sessions | Sessions list; revoke others; audit | S (**done** 2026-11-01: merged as pull request #11 after Codex reviews TASK-0029/0030; sessions moved to MySQL, PO decision) |

### Sprint 1.2 — Registries

| ID | Story | Acceptance criteria | Size |
|---|---|---|---|
| P1-20 | As an analyst, I register and maintain competitors | CRUD + lifecycle + tier + analyst assignment + classification; archive guard; audit; visibility applies | M (**done** 2026-11-01: merged as pull request #12 after Codex reviews TASK-0031/0032; entries filtered by classification only, PO decision; archive guard binding for the intelligence story) |
| P1-20a | As an admin, I grant access to one competitor instead of all records | `access_grants.competitor_id` with its foreign key and the widened scope CHECK; `GrantSet`/`AccessScope` cover competitor scopes (all-of); matrix with per-competitor grants (PO decision 2026-11-01: own story after P1-20; market grants follow P1-23 the same way) | M (**done** 2026-11-02: merged as pull request #13 after Codex review TASK-0033) |
| P1-21 | As an analyst, I record aliases and corporate relationships | Unique aliases; relationship validity; no self-relationship (DB CHECK + test) | M (**implemented** 2026-11-02 on `feature/p1-21-aliases-relationships`; pending Codex review and PO merge; parallel-request tests in P1-25) |
| P1-22 | As an analyst, I catalog products and variants, ours and competitors' | owner_kind CHECK; variants with pack size/unit; brands; categories tree | L |
| P1-23 | As an admin, I maintain markets, regions, channels, retailers, currencies, tags | Admin pages; seed data synthetic or ISO-based | M |
| P1-25 | As the platform, registry uniqueness holds under concurrency | Generated-key UNIQUE indexes (regions, brands, categories); locked overlap check for relationships; parallel-request tests | M |

### Sprint 1.3 — Sources and evidence

| ID | Story | Acceptance criteria | Size |
|---|---|---|---|
| P1-30 | As an analyst, I propose a source | Fields per `docs/04`; method forced to `manual` while collection flag off | S |
| P1-31 | As a director, I review and decide a source policy | New `source_policies` version per decision; proposer ≠ approver; lifecycle transitions tested | M |
| P1-32 | As an analyst, I upload evidence safely | Quarantine → ClamAV → SHA-256 → content-addressed storage; EICAR + MIME-spoof rejected; status visible in UI | L |
| P1-33 | As an analyst, duplicate files do not duplicate storage but keep provenance | Same bytes → one blob, two assets with their own source/capture data | S |
| P1-34 | As a permitted user, I download evidence; others cannot | Policy check → ≤60 s pre-signed URL; custody `downloaded` + audit; denial test | M |
| P1-35 | As a director, I place evidence on legal hold | Hold flag + custody event; retention cannot expire held items; blob hold = OR of its assets | S |
| P1-36 | As a security owner, evidence visibility follows its competitors and markets | Declared scopes at upload; inherited scopes on link (all-of); provenance hides inaccessible evidence; matrix rows | M |
| P1-37 | As an auditor, I can see which source policy authorized each capture | `source_policy_id` recorded; upload blocked for unapproved sources; policy-change test | S |
| P1-38 | As the platform, duplicate uploads and job retries cannot corrupt shared blobs | Atomic blob insert-or-reload; monotonic scan status; race and failure-injection tests | M |

### Sprint 1.4 — Intelligence and verification

| ID | Story | Acceptance criteria | Size |
|---|---|---|---|
| P1-40 | As an analyst, I write an intelligence item with typed, rated claims | Subjects (competitor/product/market); claim types; Admiralty rating; sanitized Markdown | L |
| P1-41 | As an analyst, I link claims to evidence with locator and excerpt | supports/contradicts/context; only `available` assets linkable | M |
| P1-42 | As an analyst, I submit for review only when evidence-backed | Submit guard tests | S |
| P1-43 | As a reviewer, I work a verification queue | Queue with assignment and audited reassignment, age, filters; only the assigned reviewer decides; approve / request changes / reject with rationale | L |
| P1-44 | As a director, I publish, retract, or supersede | Author, approver, and publisher pairwise distinct; approval bound to current version + content hash under row lock; Director rule for confidential+/high-impact; retraction record; supersession link | M |
| P1-45 | As an auditor, I trace a published item to its origins | Provenance view: claims → evidence → blob hash → source + policy version → custody → reviews → audit; hidden evidence shown as a placeholder | M |
| P1-48 | As a reader, I know when published intelligence depends on withdrawn or corrupted evidence | Evidence events flag citing items for re-review; banner shown; no auto-retraction | S |

### Sprint 1.5 — Operational hardening and acceptance

| ID | Story | Acceptance criteria | Size |
|---|---|---|---|
| P1-50 | As an auditor, audit tampering is detectable even by someone with DB access | Lag-windowed checkpoints; Ed25519-signed manifests exported to the audit-anchor bucket; verification against exported manifests; `late_commit` handling (supplementary checkpoints); business-transaction duration capped below the lag window and monitored, with an alert (TASK-0010 #4); key-rotation runbook | M |
| P1-51 | As an operator, I can restore the whole system | Restore drill covering MySQL, DB grants, MinIO versions and object-lock metadata, evidence hash sample; result logged | M |
| P1-46 | As any user, I see a dashboard of what I am allowed to see | Tiles per `docs/03`; definitions in tooltips; visibility-filtered | M |
| P1-24 | (stretch) As a user, I can quickly find a competitor or product | Header quick search (MySQL FULLTEXT, visibility-filtered); moves to Phase 2 search if time is short | S |
| P1-47 | As the team, the exit journey is proven end to end | Playwright J1 + J6; axe clean on core pages; all gates G1–G6 signed off | M |

### Phase 1 dependencies (**approved by the Product Owner, 2026-10-31**; exact versions pinned at install and recorded in ADR-0003)
- **Composer:** laravel/framework, inertiajs/inertia-laravel, laravel/fortify, laravel/sanctum, laravel/horizon, laravel/pennant, spatie/laravel-permission, league/flysystem-aws-s3-v3, a ClamAV client, league/commonmark (safe mode) with an HTML sanitizer (symfony/html-sanitizer); dev: pestphp/pest (+ arch plugin), larastan/larastan, laravel/pint.
- **npm:** vue 3, @inertiajs/vue3, typescript, tailwindcss, vite, a headless accessible component library; dev: vitest, @vue/test-utils, @playwright/test, @axe-core/playwright, eslint, vue-tsc.
- **Container images:** mysql:8.4, redis (or valkey), S3-compatible object storage, clamav, mailpit, nginx, PHP-FPM base. All free and open source. No paid services.

**Version check, 2026-10-31** (registries queried read-only; proposed pins in ADR-0003):
- Pest 5 and symfony/html-sanitizer 8.1 require PHP ≥ 8.4, so PHP 8.4 is the minimum.
- **ClamAV client:** xenolope/quahog's last release is v3.0.0 (2020-11-25). Instead of a stale dependency, a small clamd `INSTREAM` client lives in `Modules\Platform` with its own tests (no new package).
- **Headless components:** reka-ui (active). @headlessui/vue's last release was 2024-09.
- **Redis:** valkey (BSD-3-Clause), compatible with Laravel's Redis driver and Horizon.
- **MinIO:** the `minio/minio` GitHub repository is archived (last release 2025-10-15) and its Docker Hub images (`minio/minio`, `minio/mc`) are no longer published. It will not receive security fixes, so it is not used. Replacement: **SeaweedFS** (O17, decided 2026-10-31).

## 3. Later-phase epics (stories written at each phase start)

**Phase 2:** E2.1 Price observations, normalization, FX snapshots (L) · E2.2 Promotions (M) · E2.3 Product matching (M) · E2.4 Field submissions + review, mobile forms (M) · E2.5 Claim contradictions and dispute workflow (M) · E2.6 Meilisearch search via outbox, ACL filters, saved views (L) · E2.7 Alert rules, dedupe, cooldown, investigations, tasks (L) · E2.8 Battlecards v1 with versions and citations (M) · E2.9 Reports and PDF/XLSX/CSV exports with export controls (L) · E2.10 ECharts dashboards with metric definitions (M) · E2.11 Email/Teams notifications with delivery log (M) · E2.12 Privacy impact assessment for field geolocation (S).

**Phase 3:** E3.1 Python worker scaffold, signed envelope, pytest (L) · E3.2 Egress proxy and SSRF suite (M) · E3.3 RSS/Atom collector (M) · E3.4 API collector framework (L) · E3.5 Snapshots and extraction with versioned parsers (L) · E3.6 Dedupe and entity resolution (L) · E3.7 Low-confidence review queue (M) · E3.8 Source health dashboard and auto-suspend (M).

**Phase 4:** E4.0 Hardware/model benchmark spike (M) · E4.1 AI gateway, budgets, ledger (L) · E4.2 Chunking and embedding pipeline (L) · E4.3 Hybrid retrieval, post-filter, citation validator (L) · E4.4 Typed tools and pending actions (L) · E4.5 Eval harness and AIOps dashboard (L) · E4.6 AI-drafted battlecards and briefings with human approval (M) · E4.7 Strategic analysis templates (SWOT/PESTLE/Five Forces) with evidence links (M).

**Phase 5:** E5.1 PWA and offline field drafts (L) · E5.2 Push-to-talk + local STT (L) · E5.3 Intent/slot parser + disambiguation (L) · E5.4 Confirmation UI and step-up auth (M) · E5.5 Local TTS (M) · E5.6 en/fil/Taglish test set and tuning (M) · E5.7 Privacy impact assessment for voice (S).

**Phase 6 (each needs a justification ADR):** CRM/ERP adapters · BI exports · n8n and webhooks · relationship graph · forecasting and anomaly detection · realtime voice · scale-out (read replicas, worker pools).

## 4. Delivery gates (every phase)
Requirements and threat model updated · schema reviewed (Codex review mandatory for migrations) · permission checks and matrix rows · tests green · accessible UI · monitoring · docs · migration and rollback path · **human sign-off**.

## 5. Open decisions

| ID | Question | Needed by | Working assumption |
|---|---|---|---|
| O1 | Git remote and CI host (GitHub? self-hosted?) | Sprint 1.0 (P1-03, P1-07) | **Decided 2026-10-31 (PO): GitHub + Actions.** Repository created and pushed; visibility set to public by the PO (2026-10-31). Still needed: branch protection on `main` |
| O2 | Initial geography, industry, languages; applicable privacy law | Sprint 1.2 seeders | Philippines, PHP, en + fil/Taglish; configurable |
| O3 | Production hosting and sizing | Before Phase 2 release | **Partly answered 2026-10-31:** an on-premises Ubuntu 22.04 server with a web root for this app has been provided. Its connection details are kept out of the repo. Still open: whether Docker can be installed there, CPU/RAM/disk/GPU sizing, and an upgrade plan to 24.04 LTS (22.04 standard support ends April 2027). |
| O4 | GPU model/VRAM for local AI | Phase 4 start | Decided by the benchmark spike |
| O5 | Users, sources, volumes, retention, RPO/RTO | Phase 2 | RPO 24 h / RTO 8 h; 99.5% |
| O6 | Export rules and approval roles per classification | Phase 2 | Director approves confidential+ exports |
| O7 | SSO provider | Phase 6 unless needed earlier | Local auth + MFA |
| O8 | Transcript retention | Phase 5 | 30 days; no raw audio |
| O9 | Who is the source-policy owner (Director or counsel)? | Sprint 1.3 | CI Director, with counsel consulted as needed |
| O10 | Scope semantics: must a user be covered for **every** competitor/market on a record (all-of), and does evidence inherit scopes from the items it supports? (TASK-0001 Q1) | Sprint 1.1 | **Yes to both** (stricter default, now in `docs/07` §3) |
| O11 | Must the publisher be independent of the approving reviewer? (Q2) | Sprint 1.4 | **Yes**, pairwise distinct; needs ≥3 eligible people for confidential+ items |
| O12 | May evidence be uploaded without a registered, approved source? (Q3) | Sprint 1.3 | Yes, labelled "unregistered source"; named sources must be approved |
| O13 | What happens to published items whose evidence is withdrawn or corrupted? (Q5) | Sprint 1.4 | Flag for re-review with a banner; no auto-retraction |
| O14 | Who may assign reviewers, override assignments, and set/release legal holds? (Q7) | Sprint 1.3 | CI Director (and the queue owner for assignment) |
| O15 | Does "local AI" mean loopback only, or any approved host on the organization's private network? (Q8) | Phase 4 | Approved private-network hosts allowed, via the endpoint registry |
| O16 | Is the 8–14 week Phase 1 estimate a target or a deadline? If a deadline, what may be deferred? (Q9) | Before Sprint 1.0 | Target; gates are never skipped |
| O17 | Which S3-compatible store replaces MinIO (archived) for evidence, exports, and the audit anchor? It must support versioning and object lock in compliance mode (ADR-0007) | Sprint 1.0 (P1-02) | **Spike done 2026-10-31:** RustFS 1.0.4 and SeaweedFS 4.51 both passed 33/33 (`scripts/spikes/o17-object-store/`). Both Apache-2.0 and active. SeaweedFS reports telemetry by default (must run with `-master.telemetry=false`). Not yet tested: per-purpose keys that cannot bypass governance or reach other buckets. **Decided 2026-10-31 (PO): SeaweedFS 4.51**, run with `-master.telemetry=false`, identities and per-bucket permissions in a reviewed config file. The per-purpose key test becomes a P1-02 acceptance check |
| O18 | Where are sessions stored (P1-17)? | Sprint 1.1 | **Decided 2026-11-01 (PO): MySQL `sessions` table** (previously Valkey), so users can list and end their sessions |
| O19 | Is a competitor registry entry restricted by competitor-specific grants? | Sprint 1.2 | **Decided 2026-11-01 (PO): classification only**; competitor grants restrict what is recorded *about* a competitor. Competitor grants are their own story (P1-20a), market grants follow P1-23 |
| O20 | Do auditors need a restricted grant to see restricted audit rows (P1-16)? | Sprint 1.1 | **Decided 2026-11-01 (PO): yes**, no exception to ADR-0005 |
| O21 | Publish `AccessGrantCreated`/`AccessGrantRevoked` domain events? | Sprint 1.2 | **Decided 2026-11-01 (PO): yes**; published with their first consumer, since the outbox refuses events no handler subscribes to |
| O22 | Per-account login throttle trade-off (TASK-0019 #2); breached-password check | Sprint 1.1 | **Resolved 2026-11-01 (PO):** current behaviour accepted (5/min per account+IP, 10/min per account, 30/min per IP; no external breached-password service) |
| O23 | Correct the 2026-11-02 dates in records written before the machine-clock check? | — | **Decided 2026-11-01 (PO): leave as is** |
