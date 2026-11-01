# 03 — Module Specifications

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Claude Code |
| Related | `docs/01` (layout), `docs/02` (tables), `docs/08` (API contracts) |

Each module lists its responsibility, public contracts (what other modules may use), domain events, permissions, routes, and phase. Internal classes are private to the module (enforced by Pest `arch()` tests).

## Platform (shared kernel) — Phase 1
- **Provides:** `Classification` enum (ordered: public < internal < confidential < restricted); `HasPublicUlid` trait; `AccessScope` (§Identity); `AuditLogger::record(action, subject, context)`; `OutboxPublisher::publish(aggregate, eventType, payload)`; Money and Quantity value objects; `ProblemDetails` exception rendering (RFC 9457); `RequestId` middleware; security-headers middleware (CSP nonce, nosniff, frame-ancestors).
- **Rules:** `AuditLogger` and `OutboxPublisher` must be called inside the caller's DB transaction. Context keys are allowlisted per action.
- **Jobs:** `RelayOutboxJob`, `CreateAuditCheckpointJob` (scheduled), `VerifyAuditChainJob` (scheduled, alerts on mismatch).

## Identity (M01) — Phase 1
- **Responsibility:** authentication (Fortify), MFA, invitations, users, teams, roles/permissions (spatie), access grants, API tokens (Sanctum), session management.
- **Contracts:** `AccessScope` interface (implemented here, bound in the container):
  - `allows(User, Classifiable&Scoped $record): bool`
  - `applyTo(Builder $query, User): Builder` — the Eloquent scope `visibleTo($user)`
  - `searchFilter(User): string` — Meilisearch filter expression [P2]
  - `vectorFilter(User): array` [P4]
  - `effectiveGrants(User): GrantSet` (cached per request)
  - **Implemented (P1-15, 2026-11-01):** the interface, `Classifiable`, `Scoped`, `GrantSet` and the `VisibleTo` trait (`visibleTo($user)` scope) are in `Modules\Platform\Access`; `Modules\Identity\Access\GrantAccessScope` implements it and is bound per request (scoped binding). The interface takes `Authenticatable` (Platform cannot depend on Identity's `User`); any other user type, and any inactive account, sees nothing. Also `ensureVisible()` (404 for hidden records) and `forget()` (the grant and team actions clear the per-request cache). `searchFilter` and `vectorFilter` come with Phase 2 and Phase 4; record status (drafts) with the intelligence items. Models declare their scope pivots through `Scoped::scopeRelations()` and keep the classification in a `classification` column.
- **Events:** `UserInvited`, `UserActivated`, `UserSuspended`, `RoleAssigned`, `RoleRevoked`, `AccessGrantCreated`, `AccessGrantRevoked`, `MfaEnabled`, `MfaReset`, `LoginSucceeded`, `LoginFailed`.
- **Permissions:** `user.view`, `user.manage`, `team.manage`, `role.manage`, `grant.manage`, `audit.view`, `token.manage`.
- **Rules:** invitations only (no self-registration). MFA is required for any role that holds `user.manage`, `role.manage`, `grant.manage`, `intelligence.publish`, `source.approve`, or `export.run`. `EnsureMfa` middleware blocks access until MFA is enrolled. A user cannot raise their own clearance or grants.
- **Pages:** Login, MFA challenge/enroll, Accept invitation, Profile & security (sessions, MFA, tokens), Admin › Users, Teams, Roles, Access grants.

## Registry (M02, M03, M04) — Phase 1
- **Responsibility:** competitors, aliases, relationships, brands, categories, products (competitor or own), variants, markets, regions, channels, retailers, currencies, tags.
- **Contracts:** `CompetitorLookup` (find by ULID or alias, `visibleTo` aware), `ProductLookup`, `MarketLookup`. Read DTOs only; other modules never write registry tables. Uses Identity's `UserDirectory` contract (active permission holders and display names as `UserSummary`, never the `User` model; P1-20). `CompetitorLookup` implemented (P1-20a): `findVisible`, `visibleTo`, `summaries`, always filtered by `AccessScope`, returning `CompetitorSummary`; Identity uses it for competitor-scoped grants.
- **Events:** `CompetitorCreated`, `CompetitorUpdated`, `CompetitorArchived`, `ProductCreated`, `ProductUpdated`, `VariantCreated`.
- **Permissions:** `competitor.view`, `competitor.manage`, `product.view`, `product.manage`, `taxonomy.manage`.
- **Rules:** archiving is blocked while open (non-terminal) intelligence items reference the entity; it warns otherwise. Relationship validity ranges must not overlap for the same pair and type. Aliases are normalized (trim, collapse whitespace) and stay unique per competitor. Implemented in P1-21 (`CompetitorAliasService`, `CompetitorRelationshipService`; docs/02 §3.3): aliases are unique ignoring case and accents; a relationship is visible and changeable only for viewers who can see both competitors; the overlap check runs under locks on both competitor rows. Lookup by alias is added to `CompetitorLookup` with its first consumer (quick search, P1-24).
- **Pages:** Competitors (list with filters for lifecycle, tier, analyst, tag; detail tabs Profile, Aliases, Relationships, Products, Intelligence, Evidence, Activity), Products (list, detail, variants), Admin › Markets, Regions, Channels, Retailers, Categories, Tags.

## Sources (M06, M07) — Phase 1 (manual) / Phase 3 (automated)
- **Responsibility:** source registry, ownership, versioned policy decisions, lifecycle. Phase 3 adds schedules and health.
- **Contracts:** `SourceRegistry::isApprovedFor(Source, Method): bool` (used by Evidence now and Collection later); `SourceReliability`.
- **Events:** `SourceProposed`, `SourcePolicyDecided`, `SourceApproved`, `SourceSuspended`, `SourceRetired`.
- **Permissions:** `source.view`, `source.propose`, `source.approve` (Director), `source.manage`.
- **State machine:** proposed → policy_review → approved | rejected(→ proposed after edit); approved → suspended → approved | retired. Each decision appends a `source_policies` version.
- **Rules (Phase 1):** `method` must be `manual` while the `collection` flag is off. The proposer cannot approve their own source.
- **Pages:** Sources (list, detail with policy history), Policy review form.

## Evidence (M09) — Phase 1
- **Responsibility:** upload, quarantine, malware scan, hashing, content-addressed storage, custody, downloads, legal hold, retention metadata.
- **Contracts:** `EvidenceReader::find(ulid, User)` (authorization-aware, returns a DTO); `EvidenceLinkGuard::assertLinkable(asset)` (must be `available`).
- **Events:** `EvidenceUploaded`, `EvidenceScanned`, `EvidenceRejected`, `EvidenceDownloaded`, `EvidenceScopeInherited`, `EvidenceWithdrawn`, `EvidenceIntegrityCompromised`, `LegalHoldSet`, `LegalHoldReleased`.
- **Jobs:** `ScanEvidenceJob` (queue `evidence`, unique per asset, 3 tries, backoff 30/120/600 s), `VerifyEvidenceIntegrityJob` (scheduled sample re-hash).
- **Permissions:** `evidence.view`, `evidence.upload`, `evidence.download`, `evidence.hold`.
- **Rules:** MIME allowlist (PDF, PNG, JPEG, WebP, plain text, CSV, DOCX, XLSX — configurable); size cap of 50 MB (configurable); the filename is sanitized and never used as a storage key.
- **Source policy at capture** (TASK-0001 #5): if the upload names a source, that source must be `approved`, and the asset records `source_policy_id` = its current approved policy version. Uploads without a registered source are allowed for manual evidence (e.g., an analyst's own photo) and are labelled "unregistered source" in provenance. Whether to forbid them is Product Owner question Q3.
- **Scopes** (TASK-0001 #1): the uploader declares competitor/market scopes (optional; an unscoped asset is visible to anyone whose clearance meets its classification). Linking to a claim inherits the item's subjects (all-of semantics, `docs/07` §3).
- **Evidence state after publication** (TASK-0001 #10): if a linked asset is withdrawn or fails an integrity check, an `EvidenceIntegrityCompromised` / `EvidenceWithdrawn` event flags every approved or published item that cites it for re-review. Nothing is auto-retracted. Legal hold never affects readability.
- **Dedupe** (TASK-0001 #7): the scan runs on the asset's quarantine object. Only clean bytes are promoted to a blob, with an atomic insert-or-reload on `sha256`. Blob retention and hold are the maximum and OR of all referencing assets, recomputed under lock. A blob object is deleted only by a job that rechecks for zero references and no retention or hold under lock. Downloads are served as attachments through a pre-signed URL (TTL ≤ 60 s) after a policy check. Previews are images only, rendered from the evidence bucket through Laravel with `Content-Type` forced from `detected_mime`.
- **Pages:** Evidence (list, upload, detail with metadata, hash, custody timeline, linked claims).

## Intelligence (M10) — Phase 1 (core) / Phase 2 (contradictions, claim workflows)
- **Responsibility:** intelligence items, subjects, claims, evidence links, verification, publication, retraction, supersession, provenance trace.
- **Contracts:** `IntelligenceReader` (published, visibility-aware) for Dashboard, Battlecards, Reports, and AI.
- **Events:** `IntelligenceSubmitted`, `IntelligenceReviewed`, `IntelligencePublished`, `IntelligenceRetracted`, `IntelligenceSuperseded`.
- **Permissions:** `intelligence.view`, `intelligence.create`, `intelligence.submit`, `intelligence.review`, `intelligence.publish`, `intelligence.retract`.
- **State machine** (enforced in `IntelligenceStateMachine`, one Action per transition):

| From | Action | To | Guard |
|---|---|---|---|
| draft / changes_requested | submit | submitted | `intelligence.submit`; author or owner; ≥1 claim; every claim has ≥1 `supports` link to an `available` asset |
| submitted | assign / reassign | submitted | Director or queue owner; `reassign` review row + audit |
| submitted | start_review | in_review | `intelligence.review`; actor is the **assigned** reviewer; reviewer ≠ author |
| in_review | approve | approved | assigned reviewer; reviewer ≠ author; `approve` review row with `item_version` + `content_hash` |
| in_review | request_changes | changes_requested | assigned reviewer; rationale required |
| in_review | reject | rejected | assigned reviewer; rationale required |
| approved | publish | published | `intelligence.publish`; publisher ≠ author **and** ≠ approving reviewer; current-version approval with matching `content_hash` (`docs/02` §3.6), checked under row lock; Director role if classification ≥ confidential or `high_impact` |
| approved / published | flag_for_rereview | in_review | system (evidence withdrawn or integrity failure) or Director; reason recorded; published items stay visible with a "Under re-review" banner until decided |
| published | retract | retracted | `intelligence.retract`; reason required; retraction row |
| published | supersede | superseded | new item published with `supersedes_id` |

Item content (title, summary, claims, links, subjects) is **editable only in `draft` and `changes_requested`**. Every edit increments `version`. Approvals for earlier versions never count (TASK-0001 #3, #4). Concurrent edit/publish is serialized by the item row lock.

- **Pages:** Intelligence Feed (filters: status, type, competitor, classification, date semantics), Item editor (claims, evidence picker, locator/excerpt), Verification Queue (assignment, age, SLA), Provenance trace (item → claims → evidence → blob hash → source + policy version → custody → reviews → audit).

## Dashboard (M16 basic) — Phase 1
- Tiles: items by status, verification backlog by age bucket, recently published, sources by status, evidence awaiting scan or rejected, my assignments. Every query goes through `visibleTo`.
- Each metric documents its definition (numerator, denominator, freshness) in a tooltip, per skills/07.

## Phase 2+ modules (contract outline)

| Module | Key contracts / events | Notes |
|---|---|---|
| Pricing (M05) | `PriceObservationRecorded`, `PriceObservationAccepted`; `PriceHistory::for(variant, filters, user)` | append-only; normalization versioned |
| Field (M08) | `FieldSubmissionReceived`, `FieldSubmissionReviewed` | mobile-first forms; geolocation opt-in |
| Search | `SearchService::query(q, filters, user)` → ACL-filtered, MySQL re-checked | Scout + Meilisearch via outbox |
| Alerts (M15) | `AlertRaised`, `AlertAcknowledged`, `InvestigationOpened` | dedupe_key, cooldown, rule versioning |
| Battlecards (M13) | `BattlecardPublished` | versioned; citations required |
| Reports (M16) | `ReportGenerated`, `ExportCreated` | `export.run` + `can_export` grant; audited |
| Collection (M06/M07) | `CollectionRunCompleted`, `RawItemIngested` | Python workers through signed contracts (`docs/08`) |
| AI (M11/M20) | `AiAnswerGenerated`, `AiToolInvoked` | `docs/05` |
| Voice (M12) | `VoiceCommandParsed`, `VoiceActionConfirmed` | `docs/06` |
| Integrations (M17/M18) | `WebhookReceived`, `NotificationDelivered` | `docs/08` |

## UX sitemap

**Phase 1:** Overview (dashboard) · Competitors · Products · Sources · Evidence · Intelligence Feed · Verification Queue · Administration (Users, Teams, Roles, Access grants, Markets/Regions/Channels/Retailers, Categories, Tags, Failed jobs) · Audit (viewer, provenance trace) · Profile & Security.
**Later:** Prices & Promotions · Field Submissions · Search · News & Signals · Alerts & Investigations · Battlecards · Strategic Analysis · Reports · AI Research · Voice Assistant · Integrations · AI Operations.

**Cross-cutting UI requirements:** global search (Phase 1: database FULLTEXT on competitors and products; Phase 2: Meilisearch); verification and classification badges on every intelligence surface; loading, empty, and error states; keyboard-operable tables and dialogs; visible focus; color is never the only signal; dates shown with their semantic label ("Observed", "Published by source", "Collected").

## Assumptions
- One Vue component library: headless primitives styled with Tailwind, chosen in Sprint 1.0 with accessibility as the criterion.

## Risks
- A complex state machine invites edge-case bugs. Mitigation: table-driven transition tests and adversarial cases from the Codex review.

## Acceptance criteria
- Every Phase 1 route maps to a policy and a permission listed here.
- Every state transition has a positive test and a denied test.
