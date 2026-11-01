# TASK-0014 — Codex review of P1-08 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (23:17:31–23:18:12 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19441-09b1-7151-93ec-1f78a4caef6a |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0014.prompt.md`, branch `feature/p1-08-secure-defaults` @ `f3e3853` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (security work gets a Codex review before the PO merges; "proceed. merge. go") |
| Raw output SHA-256 | `3818412440e3f79f87fe78b6adeb40324eb9baa5b5017a0ce4c5e364a274709e` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — 4xx errors can disclose exception messages**

   **File:** `modules/Platform/src/Http/ProblemDetails.php:43–46`

   `HttpExceptionInterface` messages are returned for all statuses below 500. A route or framework component can construct a 400/403/404 exception containing SQL, filesystem paths, internal identifiers, or user-controlled sensitive data.

   **Scenario:** An endpoint throws `BadRequestHttpException($queryOrInternalFailure)`. The API returns that value in `detail`, violating the requirement that errors not disclose internals.

   **Fix:** Use an allowlist of deliberately safe application messages or generic details for 4xx responses. Preserve only safe headers such as `Retry-After`; never pass arbitrary exception messages through. Add tests with a 400/403/404 exception containing a secret.

2. **Medium — Static nginx responses are outside the implemented coverage**

   **Files:** `modules/Platform/src/Http/Middleware/AssignRequestId.php:22–30`, `modules/Platform/src/Http/Middleware/SecurityHeaders.php:24–49`

   The middleware covers Laravel responses, but static files and nginx-generated errors do not receive these headers or a request ID. The branch does not update nginx configuration, despite the threat-model note requiring equivalent production headers there.

   **Scenario:** A request for a directly served asset or an nginx 404 lacks CSP, `nosniff`, frame protections, and `X-Request-Id`. An upstream/proxy error also lacks the correlation ID.

   **Fix:** Add or verify production nginx configuration for the security headers and define the request-ID behavior for nginx-generated responses. Add an integration test through nginx for static assets and nginx 404/50x responses.

3. **Low — Request-ID propagation on exceptional middleware termination is not explicitly tested**

   **File:** `modules/Platform/src/Http/Middleware/AssignRequestId.php:27–28`

   The ID is attached after `$next($request)` returns. Laravel’s exception handler normally converts exceptions into responses before middleware unwinds, but this behavior is not directly tested for exceptions thrown by middleware or kernel-level failures.

   **Fix:** Add a test where an inner middleware throws and verify the final rendered response still has the generated ID. Also document the expected behavior for failures occurring before the application middleware stack.

## Missing tests

- 4xx exception messages containing SQL, paths, credentials, class names, or arbitrary user input must not appear in `detail`.
- 400, 401, 403, 404, 405, 406, 409, 410, 422, and 429 responses should verify safe details and preserved safe headers.
- JSON errors using `Accept: application/json` on non-`/api` routes.
- Exceptions thrown inside middleware still receive both security headers and `X-Request-Id`.
- Concurrent/long-running worker requests verify request IDs do not leak through Laravel `Context`.
- Horizon responses, redirects, streamed/downloaded responses, and application-generated file responses.
- nginx-served static files and nginx-generated error pages.
- Production CSP checks for the actual built Vite output and Horizon markup, not only synthetic test routes.
- All configured MySQL connections, including any future read/write variants, establish `@@session.time_zone = '+00:00'`.

## Recommendation

**Yes after fixes.**

The branch is not less safe than `main` in its implemented server-error behavior, CSP production gating, request-ID generation, or MySQL UTC configuration. However, arbitrary 4xx exception messages directly conflict with the stated error-disclosure guarantee and should be fixed before merge. nginx coverage should also be addressed or explicitly tracked as a deployment requirement.

## What I did not check

- I did not execute tests, CI scripts, migrations, nginx, Horizon, or the application.
- I did not inspect runtime behavior through nginx or production-built Vite assets.
- I did not verify the full route list, logging configuration, audit implementation, or database server/session behavior beyond static review of the changed configuration.
- I did not inspect unchanged files except where needed to understand the changed code and stated requirements.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

| # | Finding (short) | Severity | Decision | Fix |
|---|---|---|---|---|
| 1 | 4xx responses echo exception messages | High | **Accept** | `detail` is a fixed text per status. Only `Modules\Platform\Http\PublicHttpException`, whose message is written for the client, keeps its own message. Only `Retry-After`, `Allow` and the rate-limit headers are passed through. Tests: `abort()` with a password, a path and SQL for 400/403/404/405/409/418 shows none of them; a public message is shown; JSON requests to web routes get problem+json. |
| 2 | nginx-served responses lack the headers | Medium | **Accept** | `docker/nginx/default.conf` adds fallback headers (strict `default-src 'none'` CSP, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, and nginx's own `X-Request-Id`) only when the upstream response did not set them, so app pages keep their single nonce CSP. New CI step `scripts/ci/nginx-headers-test.sh` runs the real config with the pinned nginx image and checks a static file, a 403, a 404 and the 502 when PHP is unreachable. Verified live: app pages carry one CSP and one request ID. |
| 3 | Exception inside middleware not tested | Low | **Accept** | Test: a route middleware that throws still gets the security headers, the request ID header, and the same ID in the problem+json body, without the message. |
| Missing tests | — | — | **Accept in part** | Added as above. Production-build CSP checks with real Vite output and Horizon markup: verified live in local development (Vite tag and all 4 Horizon inline tags carry the nonce); a production-build check comes with the Playwright suite (P1-47). Request-ID isolation between requests is tested; long-running worker isolation (Octane) does not apply, the app runs on PHP-FPM. |
