# 00 — Product Requirements

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Product Owner (approver); Claude Code (maintainer) |
| Related | `competitive-ais.md` §2–3, §5, §19; `docs/11-delivery-roadmap.md` |

## 1. Purpose

Competitive-AIS is a standalone Competitive Intelligence (CI) platform. It turns lawful external signals and authorized internal observations into intelligence that is verified, searchable, auditable, and actionable. It assumes no particular company, ERP, CRM, brand, or region. Those connect through optional adapters.

## 2. Product principles

1. **Evidence before conclusion.** Every material claim links to evidence, a source, and a review status.
2. **Humans approve.** Verification, publication, critical alerts, and all write actions require human approval.
3. **Modular monolith first.** Add services only when a measured workload justifies them.
4. **Lawful collection only.** Never bypass access controls, CAPTCHAs, paywalls, or rate limits.
5. **AI assists, it does not decide.** AI output is never treated as an unreviewed source of truth.
6. **Accessible, responsive web/PWA.** Every feature works without voice.
7. **Configurable providers.** AI runs on **local models only** (ADR-0008).

## 3. Deployment decisions

| ID | Decision | Reference |
|---|---|---|
| D1 | One organization per deployment. No `tenant_id`. | ADR-0002 |
| D2 | Docker Compose (free Docker Engine in WSL2 for development; no Docker Desktop) with MySQL 8.4 LTS, Redis, MinIO, ClamAV, Mailpit; Meilisearch from Phase 2. | ADR-0003 |
| D3 | Local AI models only. No CI data sent to third-party AI providers. | ADR-0008 |
| D4 | Codex is engaged through manual handoffs that the user runs. | ADR-0009 |

## 4. Personas and roles

| Persona / role | Goal | Default clearance |
|---|---|---|
| Platform Super Admin | Operate the system, manage users and settings | restricted (no automatic content bypass) |
| CI Director | Approve sources, publish intelligence, own exports | restricted |
| CI Analyst | Research, collect evidence, author intelligence | confidential |
| Data Collector | Upload evidence, draft items | internal |
| Sales / Field Contributor | Submit field observations, read battlecards | internal |
| Executive Reader | Consume published intelligence and dashboards | confidential |
| Auditor | Trace provenance, read audit trail; read-only | restricted (read-only) |
| Integration Service Account | Machine access via scoped API tokens | per token |

Permissions are granular capabilities combined with classification and scope rules (`docs/07`, ADR-0005), not checks hard-coded per role.

## 5. Primary user journeys

| # | Journey | MVP phase |
|---|---|---|
| J1 | An analyst registers a competitor and an approved source, uploads evidence, writes a claim-backed intelligence item, and submits it. A reviewer verifies it and a director publishes it. | **Phase 1** |
| J2 | A field contributor submits a price, photo, or voice observation. A reviewer validates it and it joins the price history. | Phase 2 (text/photo); Phase 5 (voice) |
| J3 | An executive asks an AI question and gets a cited, permission-filtered answer with caveats. | Phase 4 |
| J4 | A salesperson opens a product battlecard with objection handling. | Phase 2 (manual); Phase 4 (AI-drafted) |
| J5 | A CI manager configures monitoring, receives deduplicated alerts, and assigns an investigation. | Phase 2 (rules); Phase 3 (automated sources) |
| J6 | An auditor traces a published conclusion back to its source, collector, transformations, approvals, and revisions. | **Phase 1** |

## 6. Scope by release

**MVP-A (Phase 1):** identity, MFA and RBAC/ABAC; competitor, product, and market registries; source registry with policy approval (manual sources only); evidence upload with scanning and hashing; intelligence items with claims; verification queue; publication and retraction; audit trail and provenance view; basic dashboard.

**MVP-B (Phase 2):** price and promotion observations; field submissions; claim contradiction workflows; Meilisearch search and saved views; alerts and investigations; battlecards v1; reports and exports; charts; notifications.

**Later (Phases 3–6):** approved automated collection; AI research and RAG; voice and PWA; enterprise connectors, forecasting, and graph intelligence.

**Out of scope until justified:** multi-tenancy, microservices, Kubernetes, paid third-party services, cloud AI processing of CI data, always-on microphone.

## 7. Nonfunctional requirements (initial targets)

| Area | Target | Notes |
|---|---|---|
| Availability | 99.5% monthly for the first production release | Revise after operating baseline (O5) |
| Latency | p95 < 500 ms for ordinary requests in a representative environment | Excludes AI, search indexing, and reports |
| Recovery | RPO 24 h, RTO 8 h | Revise by business criticality |
| Async work | Progress, timeout, cancel, retry, and error state are visible | AI, reports, collection |
| Security | 100% of endpoints authorized server-side; all sensitive access audited | Authorization matrix tests |
| Accessibility | WCAG 2.2 AA on core flows | axe checks in E2E |
| Time | Stored in UTC, displayed in the user's timezone | ADR-0004 |
| Language | English first; Filipino/Taglish for voice and reporting (assumption O2) | Configurable |

## 8. Intelligence integrity rules

- Claims are classified as **observed fact, reported claim, analyst inference, rumor, or AI hypothesis**, and inference is never shown as verified fact.
- A claim cannot be submitted without at least one supporting evidence link.
- Confidence uses the Admiralty rubric (source reliability A–F × information credibility 1–6), set by humans (ADR-0010).
- Published items are corrected by retraction or supersession, never by silent edits.
- The system never fabricates prices, dates, market share, citations, or confidence.

## Assumptions
- Initial market: Philippines (PHP currency) with English and Filipino/Taglish, configurable and not hard-coded (O2).
- Usage is internal to one organization; users number in the tens to low hundreds (O5).
- Demo and test data is synthetic: fictional competitors only.

## Risks
- Scope creep across 20 modules. Mitigation: phase gates in `docs/11`.
- Local AI quality may not meet expectations (O4). Mitigation: a Phase 4 benchmark spike before committing.

## Dependencies
- Docker Engine inside WSL2 (Ubuntu 24.04) on development machines (`docs/10`).
- Decisions O1–O8 (`docs/11` §5).

## Acceptance criteria
- The product owner has approved this document, decisions D1–D4, and the MVP-A scope.
- Each journey is traceable to epics in `docs/11`.
