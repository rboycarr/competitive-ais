# ADR-0002 — Single-organization deployment (no multi-tenancy)

- **Status:** Accepted, 2026-10-31 (Product Owner decision D1)

## Context
`competitive-ais.md` §3 requires deciding the tenancy model before schema design. `skills/02` says to include `tenant_id` only if multi-tenancy is explicitly adopted.

## Decision
Each deployment serves one organization. There are no `tenant_id` or `organization_id` columns. Isolation inside the organization comes from classification, scoped access grants, and RBAC (ADR-0005).

## Consequences
- A simpler schema and queries; no cross-tenant leakage class of bugs.
- Serving several organizations means separate deployments (separate databases, buckets, and keys).
- Converting to multi-tenancy later (Phase 6, only with a justification ADR) would require adding a tenant key to every scoped table, every index, and every queue/search/storage path. This is a significant migration, and it is accepted as a known cost.

## Alternatives considered
- Multi-tenant SaaS from day one: about 20–30% more Phase 1 effort; no current requirement.
- "Tenant-ready" (organization key on root aggregates only): rejected because it suggests isolation without providing it.
