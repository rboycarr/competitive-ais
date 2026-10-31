# 04 — Source Governance and Lawful Collection

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | CI Director (policy decisions); Claude Code (enforcement in code) |
| Related | `skills/03_COLLECTION_SKILLS.md`, `docs/07` (SSRF), `docs/02` §3.4 |

This document defines engineering controls. It is not legal advice. Source decisions that involve terms of service, licensing, or personal data must be reviewed by the organization's counsel or a designated policy owner.

## 1. Allowed collection methods (in order of preference)

| Rank | Method | Phase | Notes |
|---|---|---|---|
| 1 | Manual analyst upload / entry | 1 | Evidence uploaded with source URL and capture time |
| 2 | Official APIs (with keys under the provider's terms) | 3 | Credentials in secret storage, referenced by ID |
| 3 | RSS/Atom and public feeds | 3 | Conditional GET (ETag / Last-Modified) |
| 4 | Licensed datasets / partner data | 3 | License terms recorded in the policy |
| 5 | HTTP fetch of public pages | 3 | Only where terms and robots policy allow; rate-limited |
| 6 | Headless browser (Playwright) | 3 (exception) | Only when 5 cannot work and policy explicitly approves; isolated container |

**Prohibited, always:** bypassing logins, CAPTCHAs, paywalls, IP blocks, or anti-bot measures; using another person's credentials; fetching hosts that are not on an approved source; collecting personal data beyond what the approved policy states; the AI choosing or inventing new collection targets.

## 2. Source lifecycle

```
proposed ──(policy review)──► approved ──► suspended ──► approved
    ▲                │                         │
    └── needs_info ◄─┘ rejected                └──► retired
```

- **Propose** (`source.propose`): name, type, base URL, method, intended use, owner.
- **Policy review** (`source.approve`, not the proposer): records a new `source_policies` version with the fields below. The decision is immutable; changes create a new version.
- **Suspend** happens automatically or manually when the error rate spikes, a block is detected (403/429 patterns), terms change, or a complaint arrives. Collection stops immediately.

### Policy record fields
terms URL and summary · terms reviewed date · robots decision (allowed / disallowed / not applicable / not reviewed) · license · allowed cadence · rate budget per hour · retention days · contains personal data (and lawful basis note) · decision · decider · notes.

A source with `robots_decision = not_reviewed` or `disallowed` can never use methods 5–6.

## 3. Pipeline (Phase 3)

source registration → policy approval → schedule → fetch → raw snapshot (hash, headers, time) → extraction (versioned parser) → normalization (entities, currency, units, dates; raw is kept) → dedupe (canonical URL, content hash, near-duplicate similarity) → entity resolution → candidate claims → quality checks → **human verification** → publish → index → alert/report.

## 4. Engineering controls (Phase 3, designed now)

| Control | Implementation |
|---|---|
| Host allowlist | The worker receives a fetch job containing only the source's approved host(s); Laravel signs the job; the worker refuses unsigned or expired jobs |
| SSRF | Resolve DNS, then reject private, loopback, link-local, CGNAT, and metadata ranges (IPv4 and IPv6); pin the connection to the vetted IP; re-validate every redirect hop against the allowlist; max 3 redirects |
| Egress | Workers sit on an isolated Docker network whose only egress path is a filtering proxy |
| Rate limiting | Per-source token bucket in Redis based on `rate_budget_per_hour`; global concurrency cap; per-host politeness delay |
| Resilience | Timeouts (connect 5 s, total 30 s), exponential backoff with jitter, circuit breaker per source, dead-letter queue |
| Idempotency | Fetch job key = source + URL + schedule slot; raw item fingerprint UNIQUE |
| Provenance | Snapshot sha256, storage key, HTTP status, headers subset, fetched_at, parser version, worker version |
| Layout change | Extraction yield drops below baseline → source flagged → analyst review |
| Monitoring | Success rate, freshness, blocked attempts, cost, and queue lag per source (`docs/10`) |
| Content safety | Fetched content is untrusted: stored as data, never executed, never rendered unsanitized, quoted as data in AI prompts |

## 5. Personal data
- Default: do not collect personal data. Where it is unavoidable (e.g., executive names from official filings), record the lawful basis note in the policy, minimize the fields, and apply retention.
- Field submissions with geolocation require explicit opt-in per submission (Phase 2/5).
- Applicable privacy law (e.g., the Philippine Data Privacy Act for the assumed initial market) must be confirmed by the policy owner (open decision O2).

## Assumptions
- A designated policy owner (the CI Director or counsel) makes source decisions.

## Risks
- Terms change after approval. Mitigation: a periodic re-review date (proposal: every 12 months) and a terms-URL change check in Phase 3.

## Acceptance criteria
- Phase 1: a source cannot be approved by its proposer; non-manual methods are rejected while the collection flag is off (tests).
- Phase 3: SSRF test suite (private IPs, DNS rebinding, redirect to internal, IPv6-mapped addresses) passes; collection never touches an unapproved host (tests).
