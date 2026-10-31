# TASK-0001 — Codex review (verbatim)

| Field | Value |
|---|---|
| Date | 2026-10-31 |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort medium |
| Session | 01a19086-7a23-7e03-b180-53d5959f4019 |
| Mode | `codex exec -s read-only --skip-git-repo-check`, prompt = `TASK-0001.prompt.md` |
| Invoked by | Claude Code, **at the Product Owner's explicit request** in this session (exception to ADR-0009's manual-run default; recorded in `docs/12`) |
| Raw output SHA-256 | `b66fd2b08a6d66249294d6751e6a90d90268290f8ae8802464a63615ecf63d69` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
# Findings

## 1. Critical — Evidence authorization is not representable by the schema

**Files:** `docs/07-security-threat-model.md` §3; `docs/02-database-erd.md` §§3.2, 3.5; ADR-0005.

**Problem:** `AccessScope` requires competitor/market scope checks, but `evidence_assets` has no competitor, market, or equivalent scope relation. Evidence visibility is therefore likely determined only by its own classification. The design also states that visibility is evaluated per asset, not per content blob.

**Scenario:** A confidential evidence asset is linked to a restricted intelligence claim about Competitor A. A user with confidential clearance but no grant for Competitor A requests `/evidence/{ulid}` or downloads the asset. Because the asset is unscoped, `isUnscoped()` permits access and the user receives evidence that supports a restricted record.

**Safe fix:** Define an explicit evidence visibility model before implementation. Options include:

- typed evidence-to-competitor/market scope pivots; or
- immutable visibility metadata inherited from every linked subject; or
- an authorization rule that evaluates all linked claims/items and denies access unless every relevant path is authorized.

Use the same model for lists, single-record access, downloads, exports, search, and AI retrieval. Do not rely on classification alone.

---

## 2. High — Scope grants use polymorphic IDs without database integrity

**Files:** `docs/02-database-erd.md` §3.2; ADR-0005.

**Problem:** `access_grants.scope_type` and `scope_id` have no foreign key. Application validation cannot prevent a target from being deleted, archived, or changed between validation and grant creation. It also cannot guarantee that a competitor ID is not used with `scope_type = market`.

**Scenario:** An administrator creates a competitor grant after validation, but the target is archived or replaced before the transaction commits. The grant remains valid but points to a nonexistent or unintended row. Alternatively, a malformed import creates `scope_type = competitor, scope_id = market_id`, causing incorrect visibility.

**Safe fix:** Use separate nullable foreign keys (`competitor_id`, `market_id`) with a mutually exclusive `CHECK`, or separate typed grant tables. Add foreign keys with appropriate `RESTRICT` behavior and database-level tests.

---

## 3. High — Segregation of duties is incomplete in the intelligence state machine

**Files:** `docs/03-module-specifications.md` §Intelligence state machine; `docs/07-security-threat-model.md` §3.

**Problem:** The stated rule is `author ≠ reviewer ≠ publisher`, but the publish guard only requires `publisher ≠ author`. A reviewer may approve an item and publish it themselves. The design also does not require the acting reviewer to be the assigned reviewer or explicitly authorized for that queue.

**Scenario:** Reviewer A approves an item authored by Analyst B. Reviewer A then calls the publish action and passes the `intelligence.publish` permission check. The item becomes published without an independent publisher.

**Safe fix:** Enforce all of the following atomically:

- author, reviewer, and publisher are pairwise distinct;
- reviewer is the assigned reviewer, or reassignment is explicitly audited;
- the review decision applies to the current item version;
- publisher has the required role and classification authority;
- publication cannot proceed if the item was edited after approval.

Add a separate approval record for publication if publication is intended to be independent from review.

---

## 4. High — Approval invalidation is specified but not enforceable

**Files:** `docs/02-database-erd.md` §3.6; `docs/03-module-specifications.md` §Intelligence state machine.

**Problem:** `verification_reviews.item_version` exists, but the publish transition does not explicitly require a matching approval for the current `intelligence_items.version`. The documents state that editing invalidates earlier approvals, but no database or action-level invariant is defined.

**Scenario:** An item is approved at version 1. An editor changes the claim text, increments the item to version 2, and the publisher invokes `publish`. If the action checks only for any prior approval, version 1 approval is incorrectly reused.

**Safe fix:** Require an approval review for the exact current version, with the correct reviewer and decision. Enforce this in the publish action and test concurrent edit/publish races. Consider storing `approved_version` on the item only as a denormalized value validated against the immutable review table.

---

## 5. High — Source-policy provenance is lost after policy changes

**Files:** `docs/02-database-erd.md` §§3.4–3.5; `docs/03-module-specifications.md` §Sources; `docs/01-system-architecture.md` F1.

**Problem:** `evidence_assets` references `source_id` but not the specific `source_policies` version that authorized capture. The provenance view promises “source + policy version,” but the schema cannot reconstruct which policy was effective when evidence was uploaded.

**Scenario:** Evidence is uploaded while source policy version 1 permits manual capture. The source is later suspended and policy version 2 changes retention or permitted use. An auditor reviews the evidence and sees only the current source state, not the authorization applicable at capture time.

**Safe fix:** Store `source_policy_id` or `policy_version` on every evidence asset and source snapshot. Require the policy to be approved for the capture method at upload time. Preserve the referenced policy version permanently or under legal hold.

---

## 6. High — MySQL uniqueness constraints do not enforce documented business uniqueness

**Files:** `docs/02-database-erd.md` §3.3.

**Problem:** The documents identify the `NULL` behavior problem for `brands`, but the same issue affects:

- `regions(market_id, parent_id, name)`;
- `competitor_relationships(..., valid_from)`;
- potentially other nullable composite uniqueness rules.

The relationship constraint also does not enforce the stated “valid ranges must not overlap” rule.

**Scenario:** Two concurrent requests create two top-level regions with the same name. Both have `parent_id = NULL`, and MySQL permits both rows. Similarly, two relationship rows with overlapping validity periods are accepted if their nullable start values differ.

**Safe fix:** Use generated normalized keys/sentinel values where appropriate, or separate tables for root and child records. For temporal relationships, implement an authoritative transaction-safe overlap check, preferably with a redesign that makes the validity invariant enforceable. Add concurrency tests, not only sequential action tests.

---

## 7. High — Evidence deduplication and object-lock retention have race conditions

**Files:** ADR-0007; `docs/01-system-architecture.md` F1; `docs/02-database-erd.md` §§3.5, 8.

**Problem:** Multiple uploads of identical bytes can concurrently attempt to create the same `evidence_blobs` row and storage object. The design does not define the transaction/retry behavior when one scan fails, one row is infected, or one job observes an existing blob. It also does not define how object retention is aggregated when several assets reference one blob with different retention dates or legal holds.

**Scenario:** Upload A and Upload B have identical bytes. Both scan clean and race on the unique SHA-256 insert. One job marks the blob clean while the other marks it rejected or deletes the shared object after a failure. Alternatively, Asset A has a one-year hold and Asset B expires in 30 days; clearing B’s retention must not permit deletion of the shared object.

**Safe fix:** Define a state machine and locking strategy:

- claim/create the blob using an atomic insert and handle duplicate-key by reloading;
- never let an asset reference a blob until its scan state is clean;
- make scan status monotonic and prevent infected/error results from overwriting a clean result without an explicit quarantine action;
- calculate object retention as the maximum active asset retention and legal-hold status;
- store object version IDs and use a deletion job that rechecks all references under lock.

Add failure-injection tests for duplicate uploads and worker retries.

---

## 8. High — Audit checkpoints are not stable under concurrent inserts or retention redaction

**Files:** ADR-0006; `docs/02-database-erd.md` §3.1 and §8.

**Problem:** A Merkle checkpoint over an auto-increment ID range is underspecified. Transactions can reserve IDs in one order and commit in another, so a checkpoint may omit a later-committed row whose ID falls inside an already-closed range. Separately, IP redaction changes a row covered by an existing Merkle root.

**Scenario:** Transaction A reserves audit ID 100 but remains uncommitted. Transaction B writes and commits ID 101. The checkpoint closes through ID 101 and hashes visible rows, excluding ID 100. Transaction A then commits; verification reports a mismatch even though no tampering occurred. Later, the retention job nulls `ip_address`, changing `row_hash` and invalidating the historical checkpoint.

**Safe fix:** Define checkpoint boundaries by committed sequence/watermark, not merely numeric ID ranges, and specify isolation/locking behavior. For redaction, either:

- exclude mutable privacy fields from the immutable row hash and hash a separately versioned privacy record; or
- create a new immutable redaction event/version and maintain checkpoint generations rather than rewriting historical roots.

Document how checkpoints are anchored outside the same mutable database.

---

## 9. High — Audit tamper evidence lacks an independent trust anchor

**Files:** ADR-0006; `docs/07-security-threat-model.md` §4.

**Problem:** Row hashes and Merkle roots stored in the same database do not protect against a database administrator or compromised migrator account modifying both events and checkpoints. “Included in offsite backups” is not an independent integrity anchor unless backups are immutable and cryptographically authenticated.

**Scenario:** An attacker with database write access changes an audit event and recomputes the affected checkpoint. The application’s verification job reads the altered event and altered root and reports success.

**Safe fix:** At minimum, periodically export signed checkpoint manifests to an independent append-only destination with restricted credentials and retention lock. Record checkpoint generation, range, event count, root, and signing key identifier. Add an operational key-rotation and verification procedure.

---

## 10. Medium — Evidence links can become invalid after asset state changes

**Files:** `docs/02-database-erd.md` §§3.5–3.6; `docs/03-module-specifications.md` §Evidence.

**Problem:** A claim may link only to an `available` asset at link time, but the asset can later become `legal_hold` or another non-`available` status. The design does not define whether published claims remain valid, whether the evidence remains readable, or how retraction is triggered.

**Scenario:** A claim is approved using an available asset. A retention or legal-hold job changes the asset status. The published provenance page now shows a supporting link that fails the same availability guard used during submission.

**Safe fix:** Separate “available for linking” from “available for authorized reading,” or define a terminal evidence state model that preserves published provenance. Revalidate affected published items when evidence is rejected, found corrupt, or legally withdrawn.

---

## 11. High — Local-only AI can still exfiltrate data through a misconfigured endpoint

**Files:** `docs/05-ai-rag-design.md` §2; ADR-0008; `docs/07-security-threat-model.md` §2.

**Problem:** “OpenAI-compatible local endpoint” is a protocol choice, not a locality guarantee. The design does not require endpoint allowlisting, network isolation, TLS/authentication, or startup validation that the configured endpoint is local and approved.

**Scenario:** A configuration error points the AI gateway at a hosted OpenAI-compatible endpoint. Sensitive evidence is sent externally while the system still reports compliance with D3.

**Safe fix:** Enforce an approved endpoint registry with loopback/private-network validation, container-network allowlists, explicit startup checks, and a fail-closed behavior for unapproved destinations. Log provider identity and endpoint classification without logging prompts or evidence.

---

## 12. High — AI and voice pending actions are not explicitly bound to the confirming user/session

**Files:** `docs/05-ai-rag-design.md` §5; `docs/06-voice-design.md` §§1–3; `docs/02-database-erd.md` §4.

**Problem:** `pending_actions` contains a token hash, tool, arguments hash, and timestamps, but the design does not require a user ID, session/conversation binding, nonce, or reauthorization at execution time.

**Scenario:** A pending-action token is exposed through browser state or logs. Another session submits it and confirms the action, or the original user’s permissions are revoked after drafting but before execution.

**Safe fix:** Bind pending actions to user ID, authenticated session/device nonce, conversation/voice session, and exact normalized arguments. At execution:

- require a fresh authorization check;
- require CSRF protection and step-up authentication where applicable;
- atomically mark the action consumed;
- reject replay, changed arguments, expired actions, and revoked permissions;
- audit draft, confirmation, execution, and failure separately.

---

## 13. Medium — Phase 1 scope is materially underestimated

**Files:** `docs/11-delivery-roadmap.md` §§1–2; `docs/10-deployment-operations.md`; `docs/09-test-strategy.md`.

**Problem:** Four sprints / 6–10 weeks include custom module loading, MFA, RBAC/ABAC, grant integrity, MySQL grants, MinIO object lock, ClamAV integration, append-only audit with checkpoints, outbox delivery, accessible Vue screens, E2E tests, CI, backup restore, and a complete evidence/intelligence workflow. Several of these are individually large and security-critical.

**Scenario:** The team reaches the schedule deadline with the happy path working but defers grant enforcement, object-lock behavior, restore verification, or authorization matrix coverage. The roadmap’s exit criteria are then met only superficially.

**Safe fix:** Split Phase 1 into explicit security gates:

1. identity and authorization;
2. canonical schema and migration/grant verification;
3. evidence lifecycle;
4. intelligence workflow;
5. audit/restore/operational hardening;
6. UI accessibility and end-to-end acceptance.

Make each gate independently demonstrable and move nonessential dashboard/search functionality after the security foundation.

---

## 14. Medium — Phase 1 has no explicit implementation story for migration/grant correctness

**Files:** `docs/11-delivery-roadmap.md` §2; `docs/02-database-erd.md` §§6–7.

**Problem:** The schema requires separate database users, column-level grants, rollback testing, and MySQL-specific behavior, but the backlog has no dedicated story for creating, validating, and operating these privileges. P1-05 only describes audit behavior.

**Scenario:** Application code is developed using a privileged local connection. CI later discovers that `cais_app` cannot write a required audit or custody row, or that Laravel migrations cannot run with the intended migrator connection.

**Safe fix:** Add a schema/grants story covering account creation, least-privilege verification, migration bootstrap, local/CI parity, rollback, and failure-mode tests. Require all feature tests to run with the real application user where relevant.

---

# Missing tests for Phase 1

- Authorization matrix covering evidence assets linked to restricted competitors, unscoped evidence, source classifications, draft status, downloads, previews, Inertia props, API responses, and audit views.
- Concurrent grant creation with invalid/deleted scope targets; tests proving database foreign-key enforcement.
- Pairwise author/reviewer/publisher tests, including reviewer publishing and unassigned reviewers.
- Edit-after-approval tests proving publication fails until the current version is approved.
- Concurrent duplicate region, brand, and relationship creation tests.
- Temporal relationship overlap tests, including open-ended intervals and concurrent requests.
- Duplicate evidence upload tests with racing scan jobs, one failed scan, one retry, and shared blob retention.
- Object-lock tests for multiple assets sharing one blob with different retention dates and legal holds.
- Audit checkpoint tests with concurrent transactions, rollback gaps, late commits, and repeated checkpoint execution.
- Audit redaction tests proving historical verification remains meaningful after IP anonymization.
- Independent checkpoint-manifest verification against a tampered database.
- Source-policy version tests proving evidence retains the policy version active at capture.
- Evidence status transition tests for published claims whose supporting evidence is rejected, corrupted, or placed under legal hold.
- Local AI endpoint tests rejecting remote, unapproved, or DNS-rebinding destinations.
- Pending-action tests for cross-user replay, session mismatch, changed arguments, permission revocation, expiry, duplicate confirmation, and concurrent execution.
- Route inventory tests that verify every read, download, preview, export, WebSocket subscription, and AI/voice action invokes the same authorization service.
- MySQL 8.4 tests for all `CHECK`, `UNIQUE`, foreign-key, collation, UTC, and grant assumptions.
- Restore tests that include MinIO object versions, object-lock metadata, database grants, and evidence hash verification—not only application smoke tests.

# Unresolved assumptions / questions for the Product Owner

1. Should access to evidence be inherited from linked intelligence subjects, or does each evidence asset receive independent competitor/market scope?
2. Must a publisher be independent from the reviewer, or is reviewer approval plus director publication intentionally allowed?
3. What is the authoritative policy version for manually uploaded evidence, and must evidence capture be blocked when no approved policy exists?
4. What is the required independent audit trust anchor: immutable offsite storage, signed manifests, SIEM/WORM, or another control?
5. Are published claims allowed to remain published if their supporting evidence is withdrawn, corrupted, or no longer readable?
6. Are source and evidence classification changes retroactive, or do records retain the classification and authorization effective at capture/publication time?
7. What exact roles may assign reviewers, override assignments, place legal holds, and release legal holds?
8. Is a local AI endpoint required to be physically local/loopback, or merely operated within the organization’s private network?
9. Is the 6–10 week Phase 1 estimate a target or a hard deadline? If hard, which features may be deferred?
10. What database and object-storage backup technology will provide immutable retention and recovery evidence?

# What I did not check

- No application code, migrations, packages, Docker files, runtime configuration, or tests exist to inspect.
- I did not run commands other than read-only file reads, so no behavior, SQL constraints, grants, performance plans, restore process, or security controls were verified.
- I did not make network calls, install dependencies, run migrations, or inspect external Laravel/package compatibility.
- The review is based on the documents listed in `TASK-0001.md`, `AGENTS.md`, `CLAUDE.md`, `competitive-ais.md`, `skills/*.md`, and `handoffs/README.md`; no legal or privacy counsel review was performed.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-10-31)

Every finding was checked against the documents as written. All 14 were valid. None were rejected.

| # | Finding (short) | Severity | Decision | Evidence / rationale | Doc change |
|---|---|---|---|---|---|
| 1 | Evidence has no competitor/market scope | Critical | **Accept** | Confirmed: `evidence_assets` had no scope relation, so `isUnscoped()` would admit it. While fixing it I also found that *any-of* matching would let inherited scopes **widen** access, so matching is now all-of. | `docs/02` §3.5 (scope pivots), `docs/07` §3 (all-of predicate, evidence rules), ADR-0005 amendment, P1-36 |
| 2 | Polymorphic grant scope without FKs | High | **Accept** | Confirmed. Replaced `scope_id` with typed `competitor_id` / `market_id` FKs + CHECK. | `docs/02` §3.2, ERD |
| 3 | SoD incomplete at publish | High | **Accept** | Confirmed: `docs/07` said pairwise distinct, but the `docs/03` publish guard only checked author. Added the approver ≠ publisher rule, assigned-reviewer-only decisions, and audited reassignment. | `docs/03` state machine, `docs/07` §3, P1-43/44 |
| 4 | Version-bound approval not enforceable | High | **Accept** | Confirmed. Added `content_hash` on reviews, edits allowed only in draft/changes_requested, and a row-locked publish invariant. | `docs/02` §3.6, `docs/03` |
| 5 | Source-policy version not recorded on evidence | High | **Accept** | Confirmed. Added `source_policy_id` (required when `source_id` is set) and the approved-source rule at upload. | `docs/02` §3.5, `docs/03` Evidence, `docs/01` F1, P1-37 |
| 6 | NULL-in-UNIQUE and temporal overlap | High | **Accept** | Confirmed for regions and categories too. Added a generated-key pattern, made `valid_from` NOT NULL with precision, and added a locked overlap check. | `docs/02` §3.3, P1-25 |
| 7 | Dedupe and retention races | High | **Accept** | Partly mitigated before (deletion rechecked references) but underspecified. Blobs now hold clean content only, scans run per asset and are monotonic, insert-or-reload is atomic, and retention/hold aggregate as MAX/OR under lock. | `docs/02` §3.5, `docs/01` F1, `docs/03`, ADR-0007 amendment, P1-38 |
| 8 | Checkpoints unstable (late commits, IP redaction) | High | **Accept** | Confirmed. Moved IP and user agent to unhashed `audit_event_network` (deleted at retention, so hashed rows never change), added a lag window, and added `late_commit` handling. This also removes the UPDATE grant exception. | `docs/02` §3.1, §6, §8; ADR-0006 rewrite |
| 9 | No independent trust anchor | High | **Accept** | Confirmed. Added Ed25519-signed manifests exported to the `audit-anchor` bucket (separate credentials, compliance lock, offsite in production); verification runs against those manifests. | ADR-0006, `docs/07` §4 + T21, `docs/01`, `docs/10`, P1-50 |
| 10 | Evidence state changes after linking | Medium | **Accept** | Confirmed, and `legal_hold` was redundantly both a status and a flag. Separated linkable from readable, added a `withdrawn` status, and made withdrawal/integrity failure flag citing items for re-review. | `docs/02` §3.5, `docs/03`, P1-48 |
| 11 | "Local" AI endpoint not enforced | High | **Accept** | Confirmed. Added an endpoint registry, network-class validation, an AI network without an internet route, and fail-closed behavior. | ADR-0008, `docs/05` §2, `docs/07` + T19, `docs/02` §4 |
| 12 | Pending actions not bound to user/session | High | **Accept** | Confirmed. Added user/session/conversation binding, atomic consumption, and re-authorization at execution. | `docs/05` §5, `docs/06` §3, `docs/02` §4, `docs/07` + T20 |
| 13 | Phase 1 underestimated | Medium | **Partially accept** | Agree on risk. Estimate widened to 5 sprints / 8–14 weeks, with six security gates G1–G6 and a hardening sprint 1.5. The minimal dashboard stays in Phase 1 because `skills/10` and `CLAUDE.md` §10 list it; quick search became a stretch item. | `docs/11` §1–2 |
| 14 | No story for DB accounts/grants | Medium | **Accept** | Confirmed. Added P1-09; feature tests run as `cais_app`. | `docs/11` P1-09, `docs/09` §3 |

**Missing tests:** all adopted into `docs/09` §3 (items 10–21) and the Phase 4/5 test notes.

**Questions for the Product Owner:** recorded as O10–O16 in `docs/11` §5, each with the default now written into the docs. Q4 (trust anchor) and Q10 (backup technology) are answered by the ADR-0006 amendment and open decision O3. Q6 (retroactive classification) is answered in `docs/07` §3: current-state authorization, Director-only downgrades, audited.

**Not re-reviewed:** the amended docs have not been reviewed by Codex. A follow-up review (TASK-0002) is recommended before Sprint 1.0 merges any migration.
