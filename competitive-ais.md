# COMPETITIVE-AIS — Comprehensive Build Plan and Claude Code Master Prompt

> **Project:** Competitive-AIS (Competitive Artificial Intelligence System)
> **Document type:** Executable development brief for Claude Code, with OpenAI Codex as an independent engineering partner
> **Status:** Planning baseline — requires repository audit and user approval before implementation
> **Primary technologies:** Laravel, MySQL, Vue 3, Inertia, Redis, Meilisearch, MinIO, Python workers, optional Qdrant, configurable AI/voice providers
> **Instruction hierarchy:** Follow higher-priority instructions, root `CLAUDE.md`, and relevant files in `skills/`. This document defines product requirements and delivery expectations; it does not override security policies.

---

## 1. START HERE — DIRECT INSTRUCTIONS TO CLAUDE CODE

You are the **Lead Solution Architect, Laravel Engineering Lead, and Delivery Coordinator** for Competitive-AIS. Your engineering partner is **OpenAI Codex**, serving as an independent reviewer, test author, and implementation collaborator where its CLI, IDE extension, or API is actually configured and authorized.

**First actions — in order:**
1. Read repository-root `CLAUDE.md`, `skills/00_MASTER_SKILLS.md`, and all applicable skills `01` through `10`. Confirm which exist. If any are absent, report them; do not pretend to have read them.
2. Inspect the repository, installed dependencies, current environment, schema, routes, existing code, tests, deployment files, and Git status. **Do not assume a fresh Laravel application.**
3. Produce an **Architecture and Gap Assessment** describing existing capabilities, required changes, dependencies, security implications, costs, risks, and proposed delivery phases.
4. Produce an **Implementation Backlog** with epics, user stories, acceptance criteria, dependencies, and estimated relative effort. Identify MVP versus later releases.
5. Produce a **MySQL ERD proposal**, application/module map, integration contracts, threat model, and initial UI sitemap.
6. Propose a **Claude ↔ Codex collaboration protocol** using the workflow in section 16. Do not claim Codex reviewed anything unless its output is actually available.
7. **STOP for approval** before installing dependencies, creating migrations, deploying, modifying production data, enabling external collection, or incurring AI/API costs.

**Required initial response:** A concise repository audit, proposed architecture, first two sprints, unresolved decisions, and an explicit approval checkpoint. Never start by generating hundreds of files without a plan.

## 2. PRODUCT MISSION

Build a **standalone, enterprise-grade, modular Competitive Intelligence platform** that converts lawful external signals and authorized internal business observations into **verified, searchable, auditable, actionable intelligence**. It must support analyst workflows, executive decision-making, sales battlecards, product and pricing comparisons, early warnings, automated reports, AI research, and permission-controlled voice interaction.

This is a **new standalone product**. Do not assume a specific employer, ERP, competitor, brand, region, or existing customer dataset. External ERP/CRM and BI connections are optional adapters, never core dependencies.

### Core principles
- Evidence before conclusion; every material claim links to sources and review status.
- Human-in-the-loop for verification, publication, critical alerts, and write actions.
- Modular monolith first; services added only when justified by workload.
- API-first boundaries without premature microservices.
- Privacy, lawful collection, security, observability, and cost controls by design.
- AI is an assistant, not an unreviewed source of truth.
- Responsive web/PWA first, with accessibility and low-bandwidth support.
- Configurable model/provider choice; avoid irreversible vendor lock-in.

## 3. USERS, ROLES, AND PERMISSION MODEL

Roles: Platform Super Admin, Tenant/Organization Admin (if multi-tenant mode approved), CI Director, CI Analyst, Data Collector, Sales/Field Contributor, Executive Reader, Auditor, Integration Service Account. Support granular permissions instead of hard-coded role-only checks.

Permissions must cover entity ownership, classification, geography, department, source license, tenant, export rights, AI tool execution, and approval status. Default deny. Establish whether the product is **single-organization or multi-tenant before schema design**; if undecided, propose tenant-aware boundaries without falsely claiming full isolation.

### Primary user journeys
1. Analyst registers competitor and approved source, collects evidence, extracts claims, verifies findings, publishes intelligence.
2. Field staff submits price/photo/voice observation; reviewer validates and merges it into history.
3. Executive asks an AI question and receives a sourced, permission-filtered response with confidence caveats.
4. Salesperson retrieves product battlecard and competitor objection handling guidance.
5. CI manager configures monitoring, receives deduplicated alerts, assigns investigation, tracks resolution.
6. Auditor traces any published conclusion to original source, collector, transformations, approvals, and revisions.

## 4. TARGET ARCHITECTURE

```text
Browser / PWA / Voice UI
      │ HTTPS / authenticated APIs / WebSocket
      ▼
Nginx → Laravel + Inertia/Vue (modular monolith)
      ├── Identity, RBAC, policy & tenant boundaries
      ├── Competitors, products, markets, pricing
      ├── Sources, evidence, claims, verification
      ├── Alerts, investigations, workflows, reports
      ├── AI tool gateway, voice command gateway
      ├── APIs, integrations, audit, admin
      ├── MySQL 8.4 LTS (canonical records)
      ├── Redis + Horizon (queues/cache)
      ├── Reverb/Echo (authorized live events)
      ├── Meilisearch (derived keyword/faceted index)
      ├── Qdrant [optional] (derived vector index)
      ├── MinIO/S3 (raw evidence, files, snapshots)
      └── Python/FastAPI workers (approved extraction, OCR, enrichment)
                    ├── RSS/API/browser collection
                    ├── document parsing
                    └── NLP/embedding pipelines
AI provider gateway → configured cloud or local LLM/STT/TTS provider
Observability → structured logs, metrics, tracing, alerts, backup checks
```

**Backend:** Supported Laravel/PHP versions verified at implementation time, MySQL 8.4 LTS, Redis, queues/Horizon, Reverb if live updates are needed. **Frontend:** Vue 3, Inertia, Tailwind, accessible component system, Apache ECharts for charts. **Files:** MinIO or S3-compatible object storage with checksums and retention metadata. **Search:** Laravel Scout + Meilisearch; Qdrant only when RAG quality requires it. **Workers:** Python/FastAPI, Playwright/Scrapy/BeautifulSoup for authorized sources, document parsers, and a narrowly scoped queue/API contract. **Deployment:** Ubuntu LTS, Docker Compose for development and initial deployment, Nginx, GitHub Actions or equivalent. Avoid assuming a particular version is available without checking release/support status.

### Nonfunctional targets (initial proposals, confirm during discovery)
- 99.5% monthly application availability for first production release; stronger SLO after operating baseline.
- p95 ordinary API requests under 500 ms in a representative test environment, excluding long-running AI and search jobs.
- Async AI/research tasks expose progress, timeout, cancellation, retry, and error state.
- Recovery objectives proposed: RPO 24h and RTO 8h initially; revise by business criticality.
- All sensitive data access and mutations audited; all endpoints covered by server-side authorization.
- Load, cost, retention, and storage assumptions documented before performance commitments.

## 5. FUNCTIONAL MODULE CATALOG

**M01. Organization and Identity:** users, teams, role/permission matrix, MFA, optional SSO, sessions, service accounts, invitations, data classification.

**M02. Competitor Registry:** legal/brand names, aliases, parent/subsidiary relationships, websites, locations, sectors, ownership, executives (lawfully sourced), market coverage, tags, history, lifecycle, assigned analyst.

**M03. Product & SKU Intelligence:** competitor product catalogs, categories, variants, package sizes, specifications, imagery, claims, launch/discontinuation events, internal comparable products, battlecard inputs.

**M04. Market/Geographic Intelligence:** markets, countries, regions, distribution channels, retailers, currencies, units, tax context, geographic filtering, map-ready coordinates only when lawfully sourced.

**M05. Price & Promotion Intelligence:** immutable observations with timestamp, source, SKU, currency, package normalization, channel, location, promotion terms, evidence, review state; trend graphs, comparable-unit pricing, threshold alerts.

**M06. Source Registry & Collection:** source ownership, URL/API endpoint, terms/licensing notes, collection method, schedule, rate budget, robots/permission decision, health, credentials reference, snapshots, ingestion runs, failures, retries.

**M07. News & Event Monitoring:** RSS/API feeds, site changes, press releases, public filings, jobs, product launches, partnerships, recalls, regulatory developments; dedupe and taxonomy.

**M08. Field Intelligence:** mobile-friendly text/photo/audio submissions, structured observations, optional geolocation with consent, offline draft queue if justified, supervisor review, contributor feedback.

**M09. Evidence & Document Center:** uploads, originals, hashes, OCR/extracted text, metadata, versions, provenance, retention, malware scan, redaction, visibility controls, downloadable evidence packages.

**M10. Claims & Verification:** claim extraction, factual/inferred/rumor labels, supporting and contradictory evidence, source reliability, reviewer decisions, confidence rubric, review expiration, corrections and retractions.

**M11. AI Research Assistant:** source-cited Q&A, hybrid retrieval, filters, comparison, summaries, controlled tool calls, refusal/uncertainty handling, token/cost ledger, prompt/model versioning.

**M12. Voice Intelligence Assistant:** push-to-talk transcription, intent parsing, confirmation screens, spoken responses, optional real-time voice, multilingual capability based on provider support, complete audit of tool actions.

**M13. Competitive Battlecards:** strengths/weaknesses, features, prices, objections, differentiators, citations, reviewer approval, export and versioning.

**M14. Strategic Analysis:** SWOT, PESTLE, Porter Five Forces, competitor matrix, gap analysis, scenario assumptions, analyst commentary and evidence-linked recommendations.

**M15. Early Warning & Investigations:** configurable rules, deduplication, severity, alert ownership, acknowledgement, escalation, investigation cases, evidence collection, resolution notes.

**M16. Dashboards & Reports:** role-specific dashboards, competitor activity, price index, source coverage, trend/anomaly metrics, daily/weekly/monthly briefings, export to PDF/XLSX/CSV, scheduled delivery.

**M17. Sales/CRM Intelligence:** optional opportunity/win-loss import, customer objections, competitor encounters, feedback taxonomy, approved CRM adapters.

**M18. Integrations & Automation:** versioned REST APIs, signed webhooks, idempotent imports, connector settings, n8n optional, Teams/email notifications, optional ERP/BI connectors.

**M19. Administration & Governance:** taxonomies, retention, audit viewer, source policy approvals, provider keys, usage quotas, job health, backup status, feature flags, tenant settings if enabled.

**M20. AI Operations & Evaluation:** prompt registry, approved tools, evaluation sets, hallucination/citation tests, latency and cost metrics, model fallback, human feedback, drift and regression review.

## 6. DATA MODEL AND MYSQL REQUIREMENTS

Prepare an ERD and migrations proposal including at least these entity groups (names are illustrative; align with Laravel conventions):

- Identity: `organizations` [if approved], `users`, `teams`, `roles`, `permissions`, `memberships`, `access_grants`.
- Core: `competitors`, `competitor_aliases`, `brands`, `products`, `product_variants`, `markets`, `regions`, `channels`, `retailers`, `competitor_relationships`.
- Collection: `sources`, `source_policies`, `collection_schedules`, `collection_runs`, `source_snapshots`, `raw_ingestion_items`, `ingestion_errors`.
- Evidence: `evidence_assets`, `document_versions`, `extracted_documents`, `intelligence_items`, `intelligence_claims`, `claim_evidence_links`, `verification_reviews`, `retractions`.
- Pricing: `price_observations`, `promotion_observations`, `unit_conversions`, `currencies`, `exchange_rate_snapshots`.
- Operations: `field_submissions`, `alerts`, `alert_rules`, `investigations`, `tasks`, `battlecards`, `battlecard_versions`, `reports`, `report_runs`.
- AI: `ai_conversations`, `ai_messages`, `ai_tool_calls`, `ai_citations`, `ai_model_usage`, `ai_evaluations`, `voice_sessions`, `transcript_segments`.
- Platform: `integrations`, `webhook_events`, `outbox_events`, `audit_events`, `notification_deliveries`, `data_retention_jobs`.

### Mandatory data design
- ULIDs or UUIDs for public identifiers where beneficial; explicit consistent keys and indexes.
- Foreign keys, unique constraints, check constraints where supported, immutable provenance, UTC timestamps, timezone display conversion.
- Separate **published time, observed time, collection time, verification time**; never conflate them.
- Explicit statuses, ownership, classification, provenance, source license and confidence rubric.
- Price observations append-only; corrections via superseding/retraction records, not destructive edits.
- Original evidence checksum (SHA-256), storage key, MIME type, size, chain-of-custody events.
- All external content stored as untrusted data, never executable instructions.
- Use migrations, factories, seeders with synthetic demo data only; no real secrets or personal information in fixtures.
- Implement indexing and query plans for high-cardinality price history, alert feeds, source activity, and multi-filter search.
- If multi-tenant: enforce tenant boundaries in queries, queues, exports, AI retrieval, files, and search indexes; test isolation.
- Database backups, point-in-time recovery strategy where feasible, and tested restoration procedures.

## 7. COLLECTION PIPELINE AND LEGAL GUARDRAILS

Pipeline: **source registration → policy approval → schedule → fetch → raw snapshot → extraction → normalization → dedupe → entity resolution → claim generation → quality checks → human verification → publish → index → alert/report**.

Prioritize approved official APIs, RSS, public feeds, licensed datasets, and explicit partner data. Use Playwright only when permitted and necessary. Honor applicable terms, access controls, privacy law, source licensing, rate limits, and robots policies as appropriate. Never evade CAPTCHA, authentication, paywalls, anti-bot systems, or IP blocks. Require allowlisted hosts and SSRF-safe network egress; block localhost/private metadata endpoints. Log source policy decisions and collection provenance.

Engineering: per-source rate budget, conditional HTTP requests, exponential backoff, circuit breakers, job idempotency, content fingerprints, dead-letter queue, failure dashboards, and source-specific parsers. Do not allow AI to invent a scraper target or autonomously access unapproved domains.

## 8. AI / RAG DESIGN

Use an **AI Provider Gateway** with configured models and budgets. Retrieval pipeline: query understanding → authorization filters → MySQL structured lookup + Meilisearch keyword search + optional Qdrant semantic search → reranking → citation assembly → answer generation → factuality/citation validation → logged response.

AI capabilities: summarize, compare, classify, extract entities, detect contradictions, draft battlecards, explain pricing trends, prepare executive briefings, translate, and propose investigations. Clearly separate **facts, sourced reports, inferences, predictions, and unverified rumors**. Source-cite claims with stable internal evidence IDs, original URLs where permitted, and collection/publication dates. No citations fabricated by the model.

Tool calls must use narrow, typed server-side functions: `search_competitors`, `get_price_history`, `search_evidence`, `compare_products`, `get_alerts`, `draft_field_submission`, `draft_report`, `propose_investigation`. Never execute LLM-generated raw SQL, shell commands, arbitrary URLs, or database writes. Require explicit user confirmation for writes; extra approval for publishing, exporting restricted data, or triggering external communications. Treat retrieved content as untrusted to mitigate prompt injection.

Track model/provider, prompt version, tokens, latency, estimated cost, tool calls, failure modes, user feedback, and citations. Include eval cases for stale data, contradictory evidence, permission leakage, prompt injection, missing citations, and adversarial queries. Set spending caps and fail closed when budgets are exceeded.

## 9. VOICE DESIGN

**V1:** Browser push-to-talk → explicit microphone consent → STT → visible transcript → intent and entity extraction → server-side authorization → read-only tool action or confirmation dialog → text answer → optional TTS. **V2:** Real-time duplex conversation only after V1 security, latency, and usability tests pass.

Sample intents: “Show price drops for competitor X this quarter”; “Summarize today's verified alerts”; “Draft a field observation that brand Y costs 92 pesos per 500 grams”; “Compare two products”; “Read the weekly briefing.” Voice never bypasses normal permissions. Require confirmation for modifying, publishing, deleting, notifying, or exporting. Show recognized intent and parameters. Provide accessibility alternatives and explicit transcript retention/deletion policy. Never enable always-on microphone without clear opt-in and controls.

## 10. DASHBOARDS, ANALYTICS, AND ALERTING

Dashboards: executive pulse, competitor landscape, product comparison, price intelligence, market signals, field coverage, source health, analyst workload, AI quality/cost, system health. Metrics must define numerator, denominator, data freshness, coverage, limitations, and confidence.

Alerts: rule-based initially (price threshold, launch, facility expansion, regulatory change, abnormal mention volume, CRM win/loss pattern), then statistical anomaly detection when data quality permits. Every alert includes evidence, rule version, severity rationale, ownership, status, and dedupe key. Avoid unsupported “market share” claims without credible denominator/source data.

## 11. UX / NAVIGATION MAP

Navigation: Overview; Competitors; Products; Prices & Promotions; News & Signals; Sources; Intelligence Feed; Field Submissions; Documents & Evidence; Verification Queue; AI Research; Voice Assistant; Battlecards; Strategic Analysis; Alerts & Investigations; Reports; Integrations; Administration; Audit & Monitoring.

Provide global search, advanced filters, saved views, compare mode, responsive tables, export permissions, activity timeline, keyboard navigation, accessible colors, clear loading/error/empty states, and source/verification badges. Use realistic mockups with **synthetic data only** for prototypes.

## 12. SECURITY AND COMPLIANCE REQUIREMENTS

- Server-side RBAC/ABAC policy checks for UI, API, background jobs, file downloads, search, vector retrieval, AI and voice.
- MFA for privileged users; optional SSO after discovery; secure session handling, CSRF, rate limiting, key rotation, secrets management.
- Input validation, file scanning, content security policy, SQL parameterization, safe HTML rendering, SSRF protection, encryption in transit/at rest.
- Auditable actions: login, permission changes, evidence revisions, verification, publication, export, AI tool execution, connector access.
- Data classification, retention, deletion/hold policies, consent where applicable, privacy impact assessment for personal data.
- Signed webhooks, idempotency keys, replay protection, least-privilege API tokens.
- Threat model: cross-tenant leaks, prompt injection, malicious documents, source spoofing, fabricated evidence, scraping abuse, stolen credentials, data exfiltration, runaway AI costs, insecure voice actions.
- No hard-coded passwords, API keys, access tokens, or private customer datasets in code or prompts.

## 13. TESTING AND RELEASE QUALITY GATES

Testing layers: PHPUnit/Pest feature and unit tests; authorization matrix; MySQL migration tests; API contract tests; queue retry/idempotency tests; ingestion fixtures; Playwright browser E2E; accessibility checks; AI evaluation datasets; voice intent/confirmation tests; load tests; dependency/security scanning; backup restoration drills.

**No release without:** passing critical-path tests, migration rollback plan, secrets scan, authorization checks, evidence traceability checks, monitoring, backups, documented operations runbook, and human sign-off. Report unrun checks explicitly. Test permission boundaries on every AI retrieval path and every file download.

## 14. PHASED DELIVERY PLAN AND EXIT CRITERIA

### Phase 0 — Discovery & Architecture (approval gate)
Deliver: repo audit, product requirements, ERD, module map, source/legal policy, threat model, ADRs, UX sitemap, MVP backlog, infrastructure/cost estimate. **Exit:** approved scope, stack, data isolation mode, and budget.

### Phase 1 — Secure Foundation (MVP-A)
Build identity, RBAC, competitor/product/market registries, manual evidence upload, audit trail, source registry, verification queue, basic dashboard. **Exit:** analyst can create a sourced intelligence record and reviewer can approve it; unauthorized access blocked in tests.

### Phase 2 — Intelligence Operations (MVP-B)
Add price observations, promotions, field submissions, claims, search, saved filters, battlecards, alerts, basic reports. **Exit:** full observation-to-verification-to-alert/report workflow works with test data.

### Phase 3 — Approved Automated Collection
Add RSS/API ingestion, scheduled collectors, snapshot storage, normalization, dedupe, source monitoring, queue health. **Exit:** end-to-end collection from explicitly approved sources, with rate limiting and evidence traceability.

### Phase 4 — AI Research and RAG
Add AI gateway, hybrid retrieval, citations, document extraction, summarization, controlled tools, evaluation and cost dashboard. **Exit:** permission-filtered, cited answers meet agreed eval thresholds; unsupported answers disclose uncertainty.

### Phase 5 — Voice & Field Mobility
Add PWA improvements, push-to-talk, transcription, intent previews, confirmation, TTS, multilingual testing. **Exit:** voice cannot bypass authorization and cannot perform sensitive actions without confirmation.

### Phase 6 — Enterprise Expansion
Optional CRM/ERP/BI connectors, graph intelligence, forecasting, real-time voice, multi-tenancy, advanced analytics, horizontal scale. **Exit:** each capability justified by validated demand and operational readiness.

Do not promise dates until staffing, hosting, source access, and implementation baseline are known. Provide effort estimates as ranges and explicit assumptions.

## 15. REQUIRED DOCUMENTS AND DELIVERABLES

Under `docs/` create, after approval:
- `00-product-requirements.md` (personas, journeys, MVP/non-MVP)
- `01-system-architecture.md` (component diagram, boundaries, data flows)
- `02-database-erd.md` (entities, keys, constraints, indexes, lifecycle)
- `03-module-specifications.md` (module contracts, events, APIs)
- `04-source-governance.md` (allowed collection methods and policy)
- `05-ai-rag-design.md` (retrieval, tools, citations, evaluations)
- `06-voice-design.md` (STT/TTS, confirmations, retention)
- `07-security-threat-model.md` (controls and verification)
- `08-api-integration-contracts.md` (schemas, auth, retries)
- `09-test-strategy.md` (unit, integration, E2E, security, AI eval)
- `10-deployment-operations.md` (environment, backups, restore, monitoring)
- `11-delivery-roadmap.md` (epics, dependencies, acceptance gates)
- `12-claude-codex-handoffs.md` (task records, reviews, decisions)
- `adr/` (architecture decision records)

Each deliverable needs assumptions, risks, dependencies, acceptance criteria, and owner. Keep docs aligned with implemented code.

## 16. CLAUDE + CODEX ENGINEERING PARTNERSHIP

**Objective:** Claude Code and OpenAI Codex cooperate through explicit artifacts and independent reviews, not imagined background conversations.

### Responsibilities
| Workstream | Claude Code (lead) | Codex (partner) |
| --- | --- | --- |
| Requirements/architecture | Draft scope, module contracts, ADRs | Challenge assumptions, review tradeoffs |
| Laravel features | Primary implementation or delegated task | Independent review or bounded implementation |
| MySQL migrations | Design and implement | Review indexes, integrity, rollback, tenant isolation |
| AI/voice | Design provider gateway, policies, tool contracts | Test injection defenses, tool safety, edge cases |
| Collection | Implement approved ingestion | Review legal gates, SSRF, retries, idempotency |
| Testing | Own acceptance criteria and test runs | Propose adversarial tests, independently review failures |
| Security | Own policy enforcement and fixes | Threat-model and review critical paths |
| Documentation | Maintain source-of-truth docs | Review for gaps and inconsistencies |

### Collaboration mechanics
1. **Verify availability:** Detect whether Codex CLI/extension/API is installed and authenticated. Never assume Codex can be invoked. If unavailable, prepare a handoff file for manual use and continue only with approved scope.
2. **Create a task brief:** `handoffs/TASK-####.md` with objective, context, affected files, constraints, acceptance tests, and definition of done.
3. **Choose ownership:** one primary implementer per task. Avoid concurrent edits to the same files. Use separate Git branches/worktrees when both tools are active.
4. **Request independent review:** Codex receives diff + tests + requirements, returns findings with severity, exact paths/lines, reproducible steps, and suggested fixes. Do not call a task “Codex-reviewed” without actual review evidence.
5. **Resolve findings:** Claude fixes or assigns bounded corrections; rerun tests; record disposition in `handoffs/TASK-####-review.md`.
6. **Merge gate:** human approval for schema changes, external service costs, new data collection, auth/security changes, and production releases.
7. **Traceability:** record which assistant authored/reviewed each task, commit, and test result. AI approval never substitutes for human approval.

### Codex handoff prompt template
```text
You are the independent OpenAI Codex engineering reviewer for Competitive-AIS.
Read CLAUDE.md, competitive-ais.md, and applicable skills/*.md.
Review TASK-#### requirements, implementation diff, migrations, and test output.
Prioritize correctness, Laravel conventions, MySQL integrity, authorization,
source provenance, prompt-injection resistance, privacy, performance, and rollback.
Return: (1) critical/high/medium/low findings with file and line references,
(2) missing tests, (3) specific safe fixes, (4) unresolved assumptions.
Do not modify files unless explicitly assigned. Do not claim tests ran if not run.
```

### Work ownership and Git policy
- Protect main branch; require review and passing CI for merges.
- Use `feature/<epic>-<short-name>` and `review/<task-id>` branches/worktrees.
- Never share production credentials in handoff documents or prompts.
- No unattended agent commits, merges, deployments, or purchases without authorization.
- Keep a decision log when Claude and Codex disagree; choose based on evidence/tests and escalate high-risk decisions.

## 17. FIRST TWO SPRINTS — PROPOSED, NOT AUTHORIZED

**Sprint 1: Discovery and foundation design.** Inventory repository and infrastructure; read skills; choose tenant model; define roles/permissions; prepare ERD; define evidence lifecycle and source policy; mock core screens; establish CI/testing strategy; Codex architecture review. **Acceptance:** approved docs and backlog, no production changes.

**Sprint 2: Identity + Competitor/Evidence vertical slice.** After approval, implement Laravel auth/MFA/RBAC, competitor CRUD, source registry, evidence upload/hash, verification states, audit events, initial Vue screens, tests, migrations, backup baseline; Codex security/schema review. **Acceptance:** reviewer-approved evidence is traceable; access restrictions verified; rollback documented.

## 18. RISKS AND COST CONTROLS

High risks: unreliable scraped data, unlawful collection, fabricated AI claims, cross-tenant exposure, expensive AI inference, indexing stale data, unreviewed critical alerts, source licensing restrictions, runaway crawler jobs, evidence tampering, voice misrecognition, infrastructure under-sizing.

Controls: source approval, provenance, review queues, strict permissions, model usage budgets, queue quotas, data retention, reproducible evaluation, alert dedupe, evidence hashing, staging environment, backup/restore tests, rate-limited collectors. Provide **monthly cost scenarios** (self-hosted minimal, hybrid, enterprise) only after collecting actual hosting, traffic, source licensing, and model pricing inputs; do not invent vendor prices.

## 19. OPEN DECISIONS FOR PRODUCT OWNER

Ask for approval on these at architecture gate rather than guessing:
1. Single-organization or SaaS multi-tenant?
2. Initial competitor/industry/geographic scope and source categories?
3. On-premise Ubuntu, cloud, or hybrid hosting? Available CPU/RAM/storage/GPU?
4. Cloud AI allowed for sensitive data, or local models only? AI spending cap?
5. Languages for voice and reporting? Required transcription retention?
6. Required CRM/ERP/BI connectors, if any?
7. Expected users, sources, ingestion volume, retention, uptime and recovery objectives?
8. Human approval roles, intelligence classification and export rules?

## 20. DEFINITION OF DONE FOR EACH DEVELOPMENT TASK

A task is done only when: requirement/acceptance criteria are met; code follows `CLAUDE.md` and applicable skills; permissions and input validation are enforced; migrations are reversible where practical; audit/provenance is preserved; automated tests pass or blockers are stated; observability and failure handling exist; docs are updated; Codex review is recorded **if actually performed**; risks, deployment steps and rollback are documented; user approval gates are satisfied.

---

## 21. COPY/PASTE STARTER COMMAND FOR CLAUDE CODE

```text
We are building Competitive-AIS, a standalone, enterprise-grade Competitive
Intelligence AI platform using Laravel, MySQL, Vue 3/Inertia, Redis,
Meilisearch, MinIO, Python workers, and optional Qdrant, with AI and voice.
Open and read ./CLAUDE.md, ./competitive-ais.md, and all relevant ./skills/*.md.
You are the lead engineer; OpenAI Codex is your independent engineering
partner, but only if its actual CLI/IDE/API integration is available.

FIRST: audit this repository and environment. Do not assume a blank project.
SECOND: produce architecture, ERD, module map, security/threat model,
source governance, MVP backlog, first two sprints, and Codex handoff protocol.
THIRD: identify missing decisions and request my approval.
Do NOT install packages, create migrations, scrape websites, call paid APIs,
modify production data, or deploy until I explicitly approve the plan.
When Codex is not accessible, generate a review handoff instead of claiming
that Codex was consulted. Cite actual files and tests when reporting work.
```

**Maintenance:** Update this plan when requirements change; record decisions in ADRs; keep `CLAUDE.md` as the top-level development instruction file and this file as the product/delivery specification.
