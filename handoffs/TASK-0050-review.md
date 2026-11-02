# TASK-0050 — Codex review of P1-30 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (09:17:06–09:18:13 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19b8c-54f1-7902-8568-a64198e1a740 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0050.prompt.md`, branch `feature/p1-30-propose-source` @ `1f7d471` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `a5cf3137d6f2a44225beaeac06b7204dc46dced51ad7e38a4b48de63427e4aba` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — URL query strings can expose credentials or sensitive tokens**

   **File:** `modules/Sources/src/Http/Controllers/SourceController.php:77-78`, `modules/Sources/resources/js/Pages/Sources/Show.vue:17-18, 32`

   The full `base_url`, including its query string, is returned to the browser and rendered as a clickable external link. Userinfo is rejected, but URLs such as `https://news.example/feed?api_key=secret` cannot be reliably distinguished from ordinary URLs. Clicking the link can disclose the token through browser history, referrers, screenshots, logs, or the destination server.

   **Scenario:** An analyst proposes an API source with a signed URL or access token in the query string. Any user who can view the source receives and can click the secret-bearing URL.

   **Safe fix:** Do not render stored source URLs as clickable links. Display only a sanitized origin/path, or store/display a separately normalized URL with query and fragment removed. If query strings are required for source identity, add an explicit policy-controlled secret-handling design before exposing them.

2. **Medium — Database checks do not enforce the application’s domain-host rules**

   **File:** `modules/Sources/database/migrations/2026_11_14_000100_create_sources_table.php:48-50`

   `sources_host_check` permits values that `WebAddress::domain()` rejects, including single-label hosts, consecutive dots, and some malformed domain shapes. `sources_base_url_check` only checks the prefix `http://` or `https://`; it does not enforce valid URL parsing, no credentials, domain-only hosts, or the normalized punycode/lowercase representation.

   **Scenario:** A database writer inserts `base_url='https://user:pass@example.com'` and `host='example.com'`, or inserts `base_url='https://a..example'` with a matching host. The database accepts data that violates the source governance rules.

   **Safe fix:** Either make the database contract intentionally minimal and prevent direct app writes with a restricted insert path, or strengthen the checks to cover the documented invariants. At minimum enforce host/base URL null parity, normalized lowercase ASCII host format, at least two labels, and reject malformed dot/hyphen patterns. Add direct-DB tests for credentials, single-label domains, consecutive dots, and mismatched normalized hosts.

3. **Medium — `UserDirectory::account()` widens the cross-module user-data contract**

   **File:** `modules/Identity/src/Contracts/UserDirectory.php:34-39`, `modules/Identity/src/Directory/EloquentUserDirectory.php:39-42`

   The contract previously exposed summaries only; the new method returns the full `User` model as an `Authenticatable`. Any module with access to `UserDirectory` can now obtain profile and potentially sensitive model attributes, contrary to the contract’s stated “summaries, never the User model” rule.

   **Scenario:** A future module calls `account()` for a purpose unrelated to `AccessScope`, then reads email, status, MFA-related fields, or other model attributes. This creates a broader privacy and module-boundary surface than P1-30 requires.

   **Safe fix:** Add a narrowly scoped identity/access abstraction, such as `accountForAccessScope(int $id): ?Authenticatable`, with an explicit contract and review its returned model surface; preferably move the scope check behind an Identity-owned service so callers do not receive the model. Add an architectural test preventing unrelated consumers from using the broad method.

4. **Low — Owner lookup and source proposal are not fully atomic with all visibility assumptions**

   **File:** `modules/Sources/src/Actions/SourceService.php:49-64, 132-149`

   The proposer’s visibility is checked before entering the transaction, while the alternate owner is locked and checked inside it. This is probably safe under current grant-cache/request semantics, but it leaves two different authorization timing paths and relies on the actor’s cached grants remaining valid through the transaction.

   **Scenario:** A grant is revoked concurrently after the initial proposer visibility check but before insertion. The proposal can still commit under the request’s cached scope.

   **Safe fix:** Perform the actor visibility check inside the transaction after acquiring the relevant grant/identity locks, or document and test the intended request-snapshot semantics. Include a concurrent grant-revocation test.

## Missing tests

- Proposal with a query-string token, signed URL, fragment, or embedded sensitive-looking parameter; verify the UI never exposes it as a clickable full URL.
- Direct database inserts covering:
  - credentials in `base_url`;
  - single-label hosts;
  - consecutive dots;
  - leading/trailing hyphens;
  - uppercase hosts;
  - mismatched `base_url` and `host`;
  - invalid ports and malformed percent-encoding.
- Access-scope matrix for alternate owners at every classification, including restricted users with explicit grants.
- A contract/architecture test ensuring `UserDirectory` consumers cannot use `account()` for profile data.
- Concurrent owner suspension or permission revocation while a proposal is being created.
- Feature flag values from cached configuration and environment parsing: `true`, `false`, `"true"`, `"false"`, `1`, `0`, and unset.
- Detail-page tests confirming HTML escaping for names, descriptions, hosts, and URLs, plus no dangerous external-link behavior.

## Recommendation

**No.**

The branch exposes the complete stored URL—including potentially secret query parameters—to users and external destinations. That is a security regression relative to the project’s credential/provenance requirements. The database/application validation mismatch and widened user-model contract should also be resolved before merge.

## What I did not check

- I did not execute PHPUnit, Vitest, linting, static analysis, migrations, or builds, per the read-only review instruction.
- I did not inspect the full unrelated documentation set or every consumer of `UserDirectory`; review was limited to the requested files and the changed/shared code paths.
- I did not test MySQL CHECK behavior against a live database.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Recommendation "no", for finding 1. Findings 1 to 3 are fixed in the fix commit; finding 4 is kept as designed.

| # | Severity | Disposition |
|---|---|---|
| 1 | High | **Fixed.** A base URL may no longer carry a query string or fragment, where access keys and signed links travel. `SourceService::host()` refuses `?` and `#`, and so does the database (`sources_base_url_check`); the form says why. A source's base URL needs neither. A token placed in the path cannot be detected; the form warns against it. The detail page keeps the link (`rel="noopener noreferrer nofollow"`), now only to scheme, host, port and path. Tests: three refused URLs (key, empty query, fragment). |
| 2 | Medium | **Fixed.** The database now holds the same URL rules. `sources_base_url_check` refuses a user name or password and `?`/`#`. `sources_host_check` requires two or more labels of letters, digits and inner hyphens, not ending in a numeric label. The new `sources_host_match_check` requires `host` to be the URL's authority (case-insensitive, optional trailing dot and port). Direct-insert tests cover credentials, queries, fragments, single-label, empty-label, hyphen-edged, upper-case, trailing-dot and numeric hosts, and four mismatches, including `news.example.evil.example`. Accepted inserts cover what the service stores. |
| 3 | Medium | **Fixed.** `UserDirectory::account()` is replaced by `maySee(int $id, Classifiable&Scoped $record): bool`. Identity asks `AccessScope` itself and returns only yes or no, so no User model leaves Identity. The contract keeps its "summaries, never the User model" rule. |
| 4 | Low | **Kept as designed.** `AccessScope` decides from the grants loaded once per request (by design: P1-15, and its cache is scoped to the request or job). Every service checks the actor that way, the competitor service included. Moving this check inside the transaction would not change the answer, because the grants are cached. A grant revoked during the request takes effect on the next request. The owner check runs inside the transaction because it reads the owner's role and status under a lock (TASK-0031 #2). |

Missing tests added: query strings and fragments (service and database), database URL and host probes, feature-flag values `'false'`, `0` and `'1'`, and the detail page showing name, host and description as text. Not added: a matrix over every owner classification (the owner check uses the same `AccessScope` as the matrix-tested paths) and a concurrent grant-revocation test (see #4).
