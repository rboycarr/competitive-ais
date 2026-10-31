# MySQL Data Modeling

## Scope and operating instructions

- Use MySQL 8.4, InnoDB, utf8mb4, UTC timestamps, foreign keys, unique constraints, indexed query paths.
- Core tables: organizations, competitors, competitor_aliases, products, product_matches, price_observations, sources, source_snapshots, intelligence_items, claims, claim_evidence, documents, alerts, subscriptions, reports, users, roles, audit_events, ingestion_runs.
- Price observations are append-only and record currency, unit, pack size, region, channel, observed_at and source_snapshot_id.
- Preserve original source, content hash, captured_at, URL, license/terms metadata and provenance.
- Separate asserted fact, interpretation, unverified tip and rejected claim; verification history is immutable.
- Use soft deletes only where appropriate; never silently remove referenced evidence.
- Include tenant_id only if multi-tenancy is explicitly adopted; enforce scope in queries, jobs and indexes.
- Migration discipline: reversible changes, zero-downtime patterns, backup before destructive alterations, test restores.
