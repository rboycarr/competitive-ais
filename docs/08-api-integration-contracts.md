# 08 — API and Integration Contracts

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Claude Code |
| Related | `skills/08_INTEGRATIONS_SKILLS.md`, `docs/07` |

## 1. Internal UI
The web UI uses Inertia (server-rendered page props) with session authentication and CSRF. Inertia props are produced by API Resource classes so they share the same field-level visibility rules as the REST API.

## 2. REST API `/api/v1`

| Aspect | Contract |
|---|---|
| Auth | Sanctum personal access tokens or service-account tokens; abilities required (e.g. `competitors:read`, `evidence:write`); expiry required |
| Format | JSON; `application/json`; UTF-8; ISO 8601 UTC timestamps with `Z` |
| IDs | ULIDs only (`id` field = ULID) |
| Pagination | cursor-based: `?cursor=…&limit=50` (max 100); response `meta.next_cursor` |
| Filtering | `filter[field]=value`, allowlisted per resource |
| Errors | RFC 9457 `application/problem+json`: `type`, `title`, `status`, `detail`, `instance`, `errors` (validation map), `request_id` |
| Idempotency | `Idempotency-Key` header **required** on POST that creates resources; stored 24 h; replay returns the original response; same key with a different body → 422 |
| Rate limits | per token and per user; `429` with `Retry-After` |
| Versioning | breaking change → `/api/v2`; additive changes are allowed in v1; deprecations announced with a `Sunset` header |
| Schema | OpenAPI 3.1 document generated from code and checked in CI (contract tests) |

### Phase 1 endpoints (read-mostly; UI writes go through Inertia)

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/competitors`, `/api/v1/competitors/{id}` | `competitors:read` |
| GET | `/api/v1/products`, `/api/v1/products/{id}` | `products:read` |
| GET | `/api/v1/intelligence` (published only), `/api/v1/intelligence/{id}` | `intelligence:read` |
| GET | `/api/v1/sources` | `sources:read` |
| GET | `/api/v1/me` | any |

Write endpoints and bulk imports come in Phase 2 (field submissions, price observations), with idempotency and quarantine for malformed payloads.

## 3. Laravel ↔ Python worker contract [Phase 3+]

| Aspect | Contract |
|---|---|
| Transport | Laravel enqueues to a dedicated Redis stream or queue (`collect`, `extract`, `embed`, `stt`), or calls the worker's private HTTP API; the worker posts results to a signed internal callback |
| Message envelope | `{ "schema": "cais.collect.fetch/v1", "job_id": ULID, "issued_at", "expires_at", "payload": {…}, "signature": "<HMAC-SHA256 over canonical JSON>" }` |
| Signing | HMAC-SHA256 with a per-direction shared secret (Laravel→worker, worker→Laravel), rotated with an overlap window; reject when expired (>10 min) or replayed (job_id seen) |
| Payload rules | Workers receive only what they need: source ID, approved host(s), URL, rate budget, parser version. No user credentials and no database credentials |
| Results | `cais.collect.result/v1`: snapshot metadata (sha256, storage key in a worker-writable staging bucket, status, headers subset), extracted fields, parser version, errors. Laravel validates against JSON Schema and persists; invalid → `ingestion_errors` quarantine |
| Idempotency | Laravel upserts by `job_id` and content fingerprint |
| Versioning | Schema name includes a version; Laravel accepts N and N-1 during rollouts |

## 4. Outbound webhooks [Phase 6]
- Events: `intelligence.published`, `alert.raised`, `report.generated` (IDs and metadata only, no classified body unless the subscriber is authorized for it).
- Headers: `X-CAIS-Event`, `X-CAIS-Delivery` (ULID), `X-CAIS-Timestamp`, `X-CAIS-Signature: v1=<HMAC-SHA256(timestamp + "." + body)>`.
- Retries: exponential backoff for up to 24 h, then dead-letter; every delivery is logged in `notification_deliveries`; disable switch per subscriber.

## 5. Inbound webhooks [Phase 6]
Signature verification per connector, timestamp tolerance of 5 min, `webhook_events.idempotency_key` UNIQUE, JSON Schema validation, quarantine on failure, processing by queued job.

## 6. Connector declaration (every integration)
Each connector record or ADR declares: purpose and owner, data scope (fields in and out), classification ceiling, auth method and secret reference, rate limits, mapping, error handling, retry policy, disable switch, and data retention. No connector is mandatory (`skills/08`).

| Connector | Phase | Notes |
|---|---|---|
| Email (SMTP) | 2 | notifications; Mailpit in development |
| Microsoft Teams | 2 | incoming webhook or Graph; opt-in per user/channel |
| RSS/news APIs | 3 | via Sources and Collection |
| CRM/ERP (win/loss, opportunities) | 6 | optional adapters; read-only import first |
| Power BI / BI tools | 6 | read-only views or exports with `can_export` |
| n8n | 6 | through the versioned API and signed webhooks only |

## Acceptance criteria
- An OpenAPI document exists for every `/api/v1` endpoint and contract tests pass.
- Phase 3: HMAC envelope tests (expired, replayed, tampered, wrong version) all reject.
