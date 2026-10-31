# ADR-0005 — Authorization: RBAC capabilities + ABAC visibility in one `AccessScope`

- **Status:** Accepted, 2026-10-31

## Context
Permissions must cover classification, geography, entity scope, export rights, AI tools, and approval status, with default deny (`competitive-ais.md` §3). The biggest real-world risk is that list pages, search indexes, file downloads, and AI retrieval each implement visibility slightly differently and one of them leaks.

## Decision
- **Capabilities** (what a user may do) use spatie/laravel-permission roles and permissions, checked in Laravel Policies.
- **Visibility** (which records a user may see) is an ABAC predicate over record classification, user clearance, scoped `access_grants` (user or team; all / competitor / market; max classification; expiry; revocation), and record status. The predicate is in `docs/07` §3.
- **Single implementation:** `Modules\Platform\Access\AccessScope` provides `allows()` (single record), `applyTo()` (Eloquent query scope), `searchFilter()` (Meilisearch), and `vectorFilter()` (vector store). No other code may implement visibility.
- Hidden records return 404. Search and AI results are re-checked against MySQL after retrieval.
- **Amended 2026-10-31 after TASK-0001:** scope matching is **all-of** (every competitor and market on a record must be covered by a grant). Evidence assets carry their own declared and inherited scopes. Grants reference competitors and markets through typed foreign keys, not a polymorphic ID.
- Super Admin does not bypass content visibility.
- Segregation of duties rules are enforced in Actions and Policies.

## Consequences
- One authorization matrix test suite covers every path.
- Grant evaluation adds joins to list queries. Mitigation: per-request caching of effective grants and the indexes in `docs/02` §5.
- Search documents must carry ACL attributes and be re-indexed when ACLs change (outbox).

## Alternatives considered
- RBAC only: cannot express classification or competitor/market scopes.
- A policy engine (OPA/Cedar): powerful, but an extra service and language; reconsider in Phase 6 if rules outgrow PHP.
