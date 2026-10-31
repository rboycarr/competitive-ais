# 07 — Security Design and Threat Model

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31. Codex threat review pending (TASK-0001). |
| Owner | Claude Code (controls and tests); CI Director and Platform Admin (operational policy) |
| Related | `skills/06_SECURITY_SKILLS.md`, ADR-0005, ADR-0006, ADR-0007 |

## 1. Assets
Published and draft intelligence; evidence originals; source policies and credentials; user accounts and MFA secrets; audit trail; price history; AI conversations and transcripts; exports.

## 2. Trust boundaries
Browser ↔ Nginx/Laravel · Laravel ↔ MySQL/Redis/MinIO/ClamAV · Laravel ↔ Python workers [P3+] · workers ↔ internet [P3+] · Laravel ↔ local inference [P4+] · humans ↔ Codex (code only, no data).

## 3. Authorization model (ADR-0005)

**Capabilities (RBAC):** spatie/laravel-permission. Permissions are checked in Policies, never only in the UI.

**Visibility (ABAC), implemented once in `AccessScope`:**
```
visible(user, record) :=
     record.classification ≤ user.clearance
 AND (record.classification ≠ 'restricted' OR hasGrantCovering(user, record, explicit = true))
 AND (record.isUnscoped() OR hasGrantCovering(user, record))
 AND statusVisible(user, record)      -- drafts: author, owner, assigned reviewer, or holders of intelligence.review

-- ALL-OF semantics (TASK-0001 #1): every scope on the record must be covered.
hasGrantCovering(user, record) :=
     ∀ c ∈ record.competitorIds(): covered(user, record, 'competitor', c)
 AND ∀ m ∈ record.marketIds():     covered(user, record, 'market', m)

covered(user, record, type, id) := ∃ grant ∈ activeGrants(user) ∪ activeGrants(teams(user)):
     record.classification ≤ grant.max_classification
 AND ( grant.scope_type = 'all'
    OR (type = 'competitor' AND grant.competitor_id = id)
    OR (type = 'market'     AND grant.market_id     = id) )
```
- **All-of, not any-of:** a record about competitors A and B is visible only to users covered for both. Adding a scope can therefore only *tighten* access, which makes scope inheritance safe.
- **Evidence scope:** `evidence_assets` carry declared and inherited competitor/market scopes (`docs/02` §3.5). Linking an asset to a claim adds the item's subjects to the asset's scopes. Evidence supporting a restricted item about competitor A is therefore never visible to a user without a grant covering A.
- **Classification of linked evidence:** an asset keeps its own classification. Linking does not lower an item's protection, because the item and the provenance link are evaluated separately. Each evidence row in a provenance view is shown only if `allows(user, asset)`; otherwise it shows "1 evidence item hidden (insufficient access)".
- **Classification changes** apply immediately (current-state authorization). Downgrades require `intelligence.publish` (Director), are audited, and trigger re-indexing through the outbox.
- Super Admin does **not** automatically bypass content visibility. Admin capabilities cover users and settings, not reading restricted intelligence.
- Unauthorized single-record access returns **404** (does not reveal existence). Unauthorized actions on visible records return 403.
- The same predicate drives the Eloquent scope, Meilisearch filters [P2], vector filters [P4], file downloads, exports, AI tools, and voice. A shared **authorization matrix test** (role × clearance × grant × classification × endpoint) runs for every module.

**Segregation of duties** (TASK-0001 #3): author, approving reviewer, and publisher are **pairwise distinct**. Only the assigned reviewer may decide; reassignment is a `reassign` review row (Director or the queue owner) and is audited. Publication needs an approval for the **current version** (`docs/02` §3.6). Source proposer ≠ approver. Nobody can grant themselves access or raise their own clearance.

### Phase 1 role → permission matrix (seeded; editable by `role.manage`)

| Permission | Super Admin | CI Director | CI Analyst | Data Collector | Sales/Field | Exec Reader | Auditor | Service acct |
|---|---|---|---|---|---|---|---|---|
| user.view / user.manage | ✓ / ✓ | ✓ / – | – | – | – | – | ✓ / – | – |
| team.manage, role.manage, grant.manage | ✓ | grant.manage only | – | – | – | – | – | – |
| token.manage (own tokens) | ✓ | ✓ | ✓ | – | – | – | – | – |
| audit.view | ✓ | ✓ | – | – | – | – | ✓ | – |
| competitor.view / product.view | – ¹ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | per token ability |
| competitor.manage / product.manage | – | ✓ | ✓ | – | – | – | – | – |
| taxonomy.manage | ✓ | ✓ | – | – | – | – | – | – |
| source.view | – ¹ | ✓ | ✓ | ✓ | – | – | ✓ | per token ability |
| source.propose / source.approve / source.manage | – | ✓ / ✓ / ✓ | ✓ / – / – | – | – | – | – | – |
| evidence.view / evidence.upload / evidence.download | – ¹ | ✓ / ✓ / ✓ | ✓ / ✓ / ✓ | ✓ / ✓ / – | – | – | ✓ / – / ✓ | – |
| evidence.hold | – | ✓ | – | – | – | – | – | – |
| intelligence.view | – ¹ | ✓ | ✓ | ✓ (own drafts + published) | ✓ (published) | ✓ (published) | ✓ | per token ability |
| intelligence.create | – | ✓ | ✓ | ✓ | – | – | – | – |
| intelligence.submit | – | ✓ | ✓ | – (an analyst takes ownership and submits) | – | – | – | – |
| intelligence.review | – | ✓ | ✓ (not own items) | – | – | – | – | – |
| intelligence.publish / intelligence.retract | – | ✓ / ✓ | – | – | – | – | – | – |
| export.run [P2] | – | ✓ | – | – | – | – | – | – |

¹ Super Admin manages the platform, not content. Reading content requires holding a content role as well. Every content permission is further limited by `AccessScope` (clearance and grants). Auditor access is read-only across the board.

## 4. Controls

| Domain | Control |
|---|---|
| Authentication | Fortify; Argon2id password hashing; breached/weak password rules; login throttling per account + IP; TOTP MFA with recovery codes; MFA mandatory for privileged permissions (`EnsureMfa` middleware); invitations only; session regeneration on login and privilege change; idle timeout 30 min (configurable); session list and revoke |
| Sessions/cookies | `Secure`, `HttpOnly`, `SameSite=Lax`; CSRF on all state-changing routes; Redis-backed sessions |
| API | Sanctum tokens with abilities, expiry, and last-used tracking; per-token rate limits; `/api/v1` versioning; problem+json errors without stack traces |
| Input | Form Requests everywhere; strict types; max lengths; enum validation; ULID route binding; no mass assignment (`$fillable` explicit) |
| Output | Vue escaping by default; Markdown rendered server-side through an allowlist sanitizer; no `v-html` on unsanitized data (lint rule) |
| Headers | CSP with nonces (`default-src 'self'`; no inline scripts without a nonce); `X-Content-Type-Options: nosniff`; `Referrer-Policy: strict-origin-when-cross-origin`; `frame-ancestors 'none'`; HSTS in production |
| Uploads | Size cap; MIME by magic bytes against an allowlist; sanitized names; quarantine bucket; ClamAV; server-side SHA-256; content-addressed keys; attachment-only downloads through ≤60 s pre-signed URLs after a policy check |
| Storage | MinIO buckets private; evidence bucket versioned with object lock (governance mode); server-side encryption at rest; separate access keys per purpose (app read/write vs backup read) |
| Database | Least-privilege users (`docs/02` §6); append-only tables enforced by grants; TLS between app and DB in production; encrypted backups |
| Secrets | `.env` never committed; `.env.example` holds placeholders; gitleaks in CI; production secrets injected from the environment or a secret manager; rotation runbook |
| Audit | `AuditLogger` inside the business transaction; per-row SHA-256 over immutable fields only (IP/user agent kept in `audit_event_network`); lag-windowed Merkle checkpoints; **Ed25519-signed manifests exported to a separate audit-anchor bucket** (own credentials, compliance-mode object lock, offsite replica in production); verification checks against the exported manifests; signing key held outside the database, with a rotation procedure (ADR-0006) |
| AI egress [P4] | Approved-endpoint registry (`ai_endpoints`); resolved IP must be loopback or an approved private network; the AI containers sit on a network with no internet route; checked at startup and per call; fail closed (TASK-0001 #11) |
| Pending actions [P4/P5] | Bound to user, session, and conversation; exact normalized arguments; fresh authorization + CSRF (+ step-up where needed) at execution; consumed atomically; replay, expiry, and argument changes rejected; draft, confirmation, execution, and failure audited separately (TASK-0001 #12) |
| Network | Compose networks: `edge` (nginx), `app`, `data` (MySQL, Redis, MinIO, ClamAV, not published in production), `workers` [P3] with egress through a filtering proxy |
| Dependencies | `composer audit`, `npm audit`, pinned lockfiles; Dependabot or equivalent (O1); no unreviewed new packages |
| Logging | Structured JSON; request ID; never log passwords, tokens, MFA secrets, raw prompts, or evidence contents |

## 5. STRIDE threat model

| # | Threat (STRIDE) | Scenario | Controls | Verification |
|---|---|---|---|---|
| T1 | Information disclosure | A user lists, searches, or downloads data above their clearance or outside their grant | AccessScope everywhere; 404 for hidden records; post-filter for search | Authorization matrix tests; download denial tests |
| T2 | Elevation of privilege | An analyst grants themselves access or approves their own item | SoD guards; `grant.manage` restricted + MFA; self-grant blocked | Feature tests for each SoD rule |
| T3 | Tampering | Evidence altered after capture | Content hash; object lock; append-only custody; integrity re-hash job | Re-hash job test; UPDATE denied by grants test |
| T4 | Repudiation | A reviewer denies approving an item | Immutable reviews + audit with hash chain checkpoints | Checkpoint verification test |
| T5 | Tampering | Audit rows edited or deleted to hide activity | Grants (INSERT/SELECT only), checkpoints, offsite backups | DB grant test; checkpoint mismatch alert test |
| T6 | Spoofing | Stolen password used to log in | MFA, throttling, login audit, session revoke | Fortify tests; lockout tests |
| T7 | Tampering / malware | A malicious file uploaded and later opened by an analyst | Quarantine, ClamAV, MIME allowlist, attachment-only downloads, nosniff | EICAR test; MIME spoof test (`.pdf` holding HTML) |
| T8 | Spoofing | A fabricated "source" or fake evidence used to publish false intelligence | Source approval workflow; evidence provenance; reliability rating; human review | State-machine guard tests |
| T9 | Information disclosure | XSS through intelligence summaries or evidence titles | Sanitizer; CSP; no `v-html` on raw data | XSS payload tests in feature and E2E suites |
| T10 | Elevation | IDOR through sequential IDs | ULIDs in routes + policies (ULIDs are not a control by themselves) | Matrix tests with foreign ULIDs |
| T11 | Tampering | CSRF on state changes | Laravel CSRF; SameSite | Framework tests + E2E |
| T12 | Information disclosure | SSRF from collectors reaching internal services [P3] | Allowlist; resolve-then-connect; IP range blocks; egress proxy | SSRF suite (`docs/04`) |
| T13 | Tampering | Prompt injection makes the AI leak data or call tools [P4] | Data-quoted context; typed tools as the user; citation validator; no access widening | AI eval suite: injection and leakage = 0 |
| T14 | Denial of service / cost | Runaway crawler or AI jobs [P3/P4] | Rate budgets, queue quotas, token budgets that fail closed, circuit breakers | Budget exhaustion tests |
| T15 | Elevation | A voice command performs a write without intent [P5] | Pending actions + on-screen confirmation; voice is not authentication | Voice confirmation tests |
| T16 | Information disclosure | Exports leak restricted data [P2] | `export.run` + `can_export` grant; AccessScope on export queries; export audit; watermark | Export matrix tests |
| T17 | Information disclosure | Secrets committed or sent to Codex | gitleaks; synthetic data only in repo; handoff rules (ADR-0009) | CI secret scan; handoff checklist |
| T18 | Information disclosure | Derived index (Meilisearch) exposed directly | Not published outside the Docker network; master key; Laravel-only access | Compose config review; port scan in staging |
| T19 | Information disclosure | Misconfigured "local" AI endpoint sends CI data to a hosted service [P4] | Endpoint registry, network-class validation, no internet route for AI network, fail closed | Endpoint tests: remote host, DNS rebinding, redirect, unapproved private host |
| T20 | Elevation / spoofing | A pending-action token is replayed by another session or after permissions are revoked [P4/P5] | Binding + atomic consumption + re-authorization at execution | Replay, cross-user, session-mismatch, revoked-permission, concurrent-confirm tests |
| T21 | Tampering | An attacker with DB write access edits audit rows and recomputes checkpoints | Signed manifests in a separate locked bucket; key outside the DB | Tampered-DB verification test against exported manifests |

## 6. Privacy
- Personal data is minimized (users; possibly executive names from public filings; field submitter location only with opt-in).
- A privacy impact assessment is required before Phase 2 field geolocation and Phase 5 voice.
- Retention and legal hold are defined in `docs/02` §8. Deletion requests are handled by an admin procedure (Phase 2 runbook).

## 7. Pre-production security gates (`skills/06`)
SAST (Larastan with security rules, eslint-plugin-security), dependency scans, DAST (OWASP ZAP baseline against staging), external penetration test before the first production release, and a backup-restore exercise.

## Assumptions
- TLS terminates at Nginx in staging and production. Local development uses HTTP on localhost only.

## Risks
- A complex ABAC predicate drifts between the SQL, search, and in-memory implementations. Mitigation: one class, one test matrix run against all three paths.
- All-of scope semantics may hide multi-competitor items from users with partial grants. This is intended, the stricter default (pending Product Owner confirmation, TASK-0001 Q1).

## Acceptance criteria (Phase 1)
- T1–T11 and T17 have passing automated tests.
- A UPDATE/DELETE attempt on append-only tables as `cais_app` fails in CI.
- No route is missing a policy (an arch/route-list test asserts every route has auth middleware + an authorization call).
