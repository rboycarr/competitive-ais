# TASK-0051 — Codex verification of the TASK-0050 fix (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (09:46:54–09:47:54 UTC) |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a19ba7-9bde-7a71-9cd3-e7f8d6723b59 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0051.prompt.md`, branch `feature/p1-30-propose-source` @ `d9979de` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `971db810b06ea7e482fd641a4fcf58d2faa96e5cc3c1a4781bbe363f98091127` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0050 dispositions

1. **Finding #1 — Confirmed fixed.**  
   `SourceService::host()` rejects query strings and fragments, and the database check rejects `?` and `#`. The detail page therefore cannot expose the previously allowed token-bearing URL form. The link still exists, but only for URLs accepted by the stricter service/database rules.

2. **Finding #2 — Not fully resolved.**  
   The new checks cover credentials, queries, fragments, domain shape, null parity, and host matching. However, they do not enforce the full URL validity guaranteed by `WebAddress`.

   In particular, `sources_base_url_check` and `sources_host_match_check` accept invalid ports such as `https://news.example:99999/` or `https://news.example:00000/`. `WebAddress::isValid()`/`parse_url()` and the application’s intended URL rules should reject these.

3. **Finding #3 — Confirmed fixed.**  
   `UserDirectory::account()` was removed. `maySee()` returns only a boolean and delegates to `AccessScope`; unknown and inactive users are denied by the concrete `GrantAccessScope`.

4. **Finding #4 — Accepted as designed.**  
   The actor check intentionally uses the request/job-scoped grant snapshot. The owner check remains transactionally locked and uses the same visibility service. This matches the documented request-snapshot semantics.

## 2. New findings

### Medium — Database URL checks accept invalid ports

**File:** `modules/Sources/database/migrations/2026_11_14_000100_create_sources_table.php:47-51`

**Problem:** The database rules accept any one-to-five-digit port, including ports outside `1–65535` and port zero. This is weaker than the application’s URL validation and contradicts the claim that the database holds the same URL rules.

**Scenario:** A direct database writer inserts:

```text
base_url = https://news.example:99999/
host = news.example
```

The CHECK constraints accept it, although the application’s URL parser should reject it.

**Safe fix:** Validate the port range in the application and database contract, or explicitly narrow the database check to the application’s accepted port grammar. Add direct-insert tests for `:0`, `:00000`, `:65536`, and `:99999`, while accepting `:1` and `:65535`.

## 3. Recommendation

**Yes after fixes.**

The original high-risk URL exposure issue and the widened Identity contract are fixed, and the retained request-snapshot behavior is acceptable by design. The database/application validation mismatch should be corrected before merge.

## 4. What I did not check

- I did not execute PHPUnit, Vitest, linting, static analysis, migrations, or builds.
- I did not run the checks against a live MySQL 8.4 instance.
- I did not inspect every unrelated repository document or every historical consumer outside the changed paths.
- I did not modify any files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

TASK-0050 #1 and #3 confirmed fixed, #4 accepted as designed. #2 is reopened by the new medium finding below, now fixed. Recommendation "yes after fixes".

| # | Severity | Disposition |
|---|---|---|
| Ports | Medium | **Fixed.** Both sides now allow a port only from 1 to 65535, without leading zeros. `sources_host_match_check` matches that range exactly. `SourceService::host()` refuses any other port and an empty one (`news.example:/`), which the old check let the database refuse with an error. The service runs its port check after the domain check, so IPv6 literals keep their own message. Tests: database refusals for `:0`, `:00000`, `:065535`, `:65536`, `:99999` and `:/`; database and service accept `:1`, `:59999` and `:65535`; the service refuses `:08080` with the port message and `:0`, `:/` and `:65536` with a base URL error (PHP's URL check may answer first). |
