# ADR-0004 — Identifiers and time semantics

- **Status:** Accepted, 2026-10-31

## Context
`competitive-ais.md` §6 asks for ULIDs or UUIDs for public identifiers and for publication, observation, collection, and verification times to stay separate. InnoDB clusters rows by primary key, so random keys fragment inserts on high-volume tables.

## Decision
- **Keys:** `BIGINT UNSIGNED AUTO_INCREMENT` primary keys used internally and for foreign keys; a `ulid CHAR(26) UNIQUE` column on every entity exposed through URLs, APIs, events, or exports. Route model binding uses the ULID.
- **Time storage:** `DATETIME(6)` in UTC everywhere (`APP_TIMEZONE=UTC`, MySQL session time zone `+00:00`). `TIMESTAMP` is not used.
- **Time semantics:** `published_at` (the source published it), `observed_at` (the fact held in the world), `collected_at` / `captured_at` (we obtained it), `verified_at` (review decision), `released_at` (made visible internally). They are never merged into one field. The UI always labels which one it shows.
- **Display:** converted to the user's timezone at presentation.

## Consequences
- Compact, ordered clustered indexes; public IDs that do not reveal record counts.
- Two identifiers per row; resources must never leak the internal `id`. An arch/resource test checks this.
- ULIDs are not an access control. Policies still apply (`docs/07` T10).

## Alternatives considered
- ULID as primary key: simpler, but larger secondary indexes and FKs.
- UUIDv7: comparable; ULID chosen for Laravel's built-in support and shorter text form.
