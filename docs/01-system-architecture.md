# 01 — System Architecture

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Claude Code (Lead Architect); Codex (reviewer) |
| ADRs | 0001, 0002, 0003, 0006, 0007, 0008 |

## 1. Component view

```
Browser (Vue 3 + Inertia; PWA in Phase 5)
   │ HTTPS · session cookie + CSRF · WebSocket (Reverb, private channels)
   ▼
Nginx ──► Laravel (PHP-FPM) — modular monolith
            ├─ HTTP: Inertia pages, /api/v1 (Sanctum tokens), signed callbacks
            ├─ Horizon workers — queues: default, evidence, index, notify, collect[P3], ai[P4], voice[P5]
            ├─ Scheduler — outbox relay, audit checkpoints, retention, health checks
            └─ Reverb — authorized realtime events (alerts, job progress)
   ├─ MySQL 8.4 LTS ........ canonical records (source of truth)
   ├─ Redis ................ cache, queues, sessions, rate limiters
   ├─ MinIO (S3 API) ....... buckets: evidence-quarantine, evidence (versioning + object lock), exports,
   │                          audit-anchor (signed checkpoint manifests; own credentials; compliance-mode lock)
   ├─ ClamAV (clamd) ....... upload malware scanning
   ├─ Mailpit .............. development mail only
   ├─ Meilisearch [P2] ..... derived lexical + hybrid-vector index (ACL attributes per document)
   ├─ Python/FastAPI [P3+] . collection, parsing/OCR, embeddings, STT — private network, egress allowlist
   ├─ Local inference [P4+]  OpenAI-compatible endpoint (LLM, embeddings); local STT/TTS [P5]
   └─ Qdrant [optional] .... only if Meilisearch hybrid search fails AI evaluations
Observability: structured JSON logs, Horizon metrics, health endpoints, backup checks (docs/10)
```

## 2. Ownership boundaries

| Component | Owns | Must not |
|---|---|---|
| Laravel | identity, authorization, workflows, validation, business rules, audit, API contracts | trust frontend authorization; run LLM-generated SQL or shell |
| MySQL | structured canonical data, constraints, append-only enforcement through grants | hold only embeddings as evidence |
| MinIO | original evidence bytes, snapshots, exports | be publicly readable; serve objects without a policy check |
| Meilisearch / Qdrant | derived, rebuildable indexes | be treated as canonical; return results without an ACL filter and re-check |
| Redis | ephemeral state | be the only copy of anything durable |
| Python workers | fetch, parse, and extract from **approved** sources; embeddings; STT | write to MySQL directly; fetch unapproved hosts; hold user credentials |
| Local inference | text generation, embeddings | receive database credentials; act without Laravel tool mediation |

## 3. Module layout (ADR-0001)

```
modules/<Module>/
  src/  <Module>ServiceProvider.php (extends Modules\Platform\Support\ModuleServiceProvider),
        Models, Actions, Policies, Http/{Controllers,Requests,Resources},
        Events, Listeners, Jobs, Enums, Data, Contracts
  database/ migrations, factories, seeders
  routes/   web.php, api.php (api.php is served under /api/v1)
  resources/js/Pages/<Module>/
  tests/    Unit, Feature
```

- PSR-4 maps `Modules\<Module>\` to `modules/<Module>/src` and `Modules\<Module>\Tests\` to `modules/<Module>/tests`. Composer has no wildcard, so each module adds both lines to `composer.json`; a test fails if a module directory is missing them.
- `App\Providers\ModulesServiceProvider` registers every `modules/*` provider (Platform first, then alphabetically) and fails loudly for a module directory without one. The base provider loads the module's migrations and routes by convention.
- PHPUnit/Pest discover tests under `modules/*/tests/{Unit,Feature}`; architecture tests live in `tests/Architecture`.
- Frontend (2026-11-01): `resources/js/app.ts` starts Inertia; page `<Module>/<Page>` resolves to `modules/<Module>/resources/js/Pages/<Module>/<Page>.vue` (`resources/js/pages.ts`, names validated). Shared layouts and components live in `resources/js` and are imported as `@/…`. The root view is `resources/views/app.blade.php`; `Modules\Platform\Http\Middleware\HandleInertiaRequests` runs in the `web` group. Component tests sit next to the component (`*.test.ts`). The Inertia progress bar is off, because it injects a style element without the CSP nonce.
- A module may depend on `Modules\Platform` and on other modules' `Contracts` and `Events` only. Platform depends on no other module. Modules do not use `App\`. Pest `arch()` tests enforce this (`tests/Architecture/ModuleBoundariesTest.php`; proven on 2026-10-31 with temporary modules: importing another module's `Models`, importing `App\`, or Platform importing any module each fail the suite, while importing `Contracts` passes).
- **Shared kernel `Modules\Platform`:** `Classification`, `HasPublicUlid`, `AccessScope`, `AuditLogger`, `OutboxPublisher`, Money/Quantity value objects, problem+json errors, feature flags (Pennant).

| Module | Phase | Catalog |
|---|---|---|
| Platform | 1 | shared kernel, audit, outbox, settings |
| Identity | 1 | M01 |
| Registry | 1 | M02, M03, M04 |
| Sources | 1 (manual) / 3 (feeds) | M06, M07 |
| Evidence | 1 | M09 |
| Intelligence | 1 (basic claims) / 2 | M10 |
| Dashboard | 1 (basic) / 2 | M16 |
| Pricing, Field, Alerts, Search, Battlecards, Reports | 2 | M05, M08, M15, M13, M16 |
| Collection | 3 | M06, M07 |
| AI | 4 | M11, M14 (AI-assisted), M20 |
| Voice | 5 | M12 |
| Integrations | 2 (notifications) / 6 | M17, M18 |
| Admin | 1+ | M19 |

## 4. Key data flows

**F1 — Evidence upload (Phase 1)**
`POST /evidence` (Form Request: size, MIME allowlist; if a source is named it must be approved, and its current policy version is captured) → stream to `evidence-quarantine` → DB transaction: `evidence_assets(status=quarantined, scan_status=pending)` + declared scopes + custody `uploaded` + audit + outbox → `ScanEvidenceJob` (queue `evidence`, unique per asset): ClamAV scan of **this asset's** quarantine object → infected: asset `rejected`, quarantine object deleted, no blob touched → clean: compute SHA-256 server-side → copy to `evidence/sha256/ab/cd/<hash>` (idempotent: same key, same bytes) → `INSERT … ON DUPLICATE KEY` on `evidence_blobs(sha256)` and reload → link the asset to the blob, status `available` → recompute blob retention/hold under lock → custody `scanned` → delete the quarantine object. Retries are safe at every step (TASK-0001 #7).

**F2 — Intelligence lifecycle (Phase 1)**
draft → submitted (gate: ≥1 claim, each claim has ≥1 `supports` link to an `available` asset) → in_review → approved | changes_requested | rejected → published (`released_at` set) → retracted | superseded. Each transition is one transaction: state change, append-only `verification_reviews` row where relevant, audit event, outbox event.

**F3 — Outbox relay**
Business transaction writes `outbox_events` → scheduler or queue `RelayOutboxJob` claims a batch (`SELECT … FOR UPDATE SKIP LOCKED`) → dispatches handlers (indexer, notifier, Reverb broadcast) → sets `dispatched_at`. Handlers are idempotent on `outbox_events.ulid`.

**F4 — Authorized read**
Controller → Policy (capability + `AccessScope::allows($user, $record)`) → for lists, `Model::visibleTo($user)` scope. Meilisearch queries use `AccessScope::searchFilter($user)`, then results are re-checked against MySQL before rendering.

**F5 — Collection [P3]** and **F6 — RAG [P4]:** see `docs/04` and `docs/05`.

## 5. Reliability

- Jobs are idempotent (unique keys, natural-key upserts), use exponential backoff with jitter, and set `tries` and `timeout`.
- Horizon's `failed_jobs` table acts as the dead-letter queue. Admins see a review and retry screen (Phase 1 admin, basic).
- Outbox delivery is at-least-once and consumers deduplicate.
- Feature flags gate Phase 2+ capabilities. Collection and AI are off by default.
- Health endpoints `/up` (liveness) and `/health` (authenticated readiness: DB, Redis, MinIO, ClamAV, queue lag).

## 6. Environments

| Env | Purpose | Data |
|---|---|---|
| local | Docker Compose on Windows + WSL2 (`docs/10`) | synthetic only |
| ci | ephemeral MySQL 8.4 service | synthetic only |
| staging | production-like Compose on Ubuntu LTS | synthetic or approved masked data |
| production | Ubuntu LTS + Compose (O3) | real; backups and monitoring required |

The local environment uses non-XAMPP ports so existing XAMPP projects keep working: app 8080, MySQL 3307, Redis 6380, MinIO 9000/9001, Mailpit 8025, Reverb 8081, Meilisearch 7700.

## Assumptions
- Single organization (ADR-0002); horizontal scale-out is not needed before Phase 6.
- Each sprint will confirm Laravel and package support for the pinned PHP version (ADR-0003).

## Risks
- Docker bind-mount performance on NTFS. Mitigation: measure in Sprint 1.0; move the working copy into the WSL filesystem if it is too slow.
- Module boundary erosion. Mitigation: arch tests in CI.

## Dependencies
- WSL2 + Docker Engine (in Ubuntu 24.04) set up per `docs/10` §1.1.
- Dependency list approved (`docs/11` §4).

## Acceptance criteria
- Every Phase 1 component has an owner, a failure mode, and a health check.
- The arch-test rules are written before the first module is merged.
