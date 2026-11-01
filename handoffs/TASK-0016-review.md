# TASK-0016 — Codex review of the frontend shell (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (00:51:54–00:52:51 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19497-724b-7300-8ea2-d81047fd14be |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0016.prompt.md`, branch `feature/frontend-shell` @ `9b998d3` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `ee09011c5f95b27c5faf76e0354c0c31d5ee1c99fbcf972cdbe32f62e3748ae5` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

No findings. The reviewed implementation does not appear to weaken the stated P1-08 guarantees:

- `resources/views/app.blade.php:7–12` uses the existing nonce-aware Vite/Inertia integration.
- Inertia page data is emitted through the framework’s JSON path rather than raw HTML.
- `resources/js/pages.ts:3–20` rejects traversal-like names and resolves only keys present in the constrained `import.meta.glob()` map.
- `HandleInertiaRequests.php:20–25` shares only the request ID; no sensitive user, permission, or intelligence data is globally exposed.
- `eslint.config.js:11–16` applies `vue/no-v-html` to all `.vue` files matched by ESLint, including module files.
- The split audit gate in `scripts/ci/frontend.sh:21–24` matches the documented policy: runtime advisories fail the build; development-only moderate advisories do not.
- Dependency lockfiles are updated and the documented two-week review exception is recorded in `docs/10-deployment-operations.md:95`.

## Missing tests

The branch would benefit from these additional tests:

1. Assert that an Inertia payload containing `</script>`, HTML, and quote characters is safely encoded and cannot terminate the JSON script element.
2. Assert that a malicious or unknown valid-looking page name such as `Unknown/Home` cannot resolve, even if passed by the server.
3. Assert that every `.vue` file under both `resources/js/` and `modules/*/resources/js/` is included in the lint target.
4. Add a browser-level CSP test covering dynamic Inertia `<Head>` output and Vite development-mode tags.
5. Add a dependency-policy test ensuring runtime advisories fail while documented development-only moderate advisories remain permitted.

## Recommendation

Yes — safe to merge into `main`.

This recommendation is based on static inspection only. I did not independently execute the branch’s reported CI, audit, build, or live-browser checks.

## What I did not check

- I did not run tests, builds, lint, type checks, `npm audit`, or backend CI.
- I did not make live HTTP requests or inspect the application through nginx/Vite.
- I did not independently validate the contents of the full `package-lock.json` or `composer.lock` beyond reviewing the changed dependency configuration and diff.
- I did not review unchanged P1-08 middleware implementation except where needed to assess the frontend integration.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

No findings; recommendation "yes".

| Suggested test | Decision | Rationale / change |
|---|---|---|
| 1. Hostile prop cannot break out of the page-data JSON | **Added** | `InertiaShellTest::test_page_data_cannot_break_out_of_its_json_block`: a prop with `</script><script>…`, quotes and HTML stays inside the JSON block and round-trips intact. |
| 2. Unknown valid-looking page name | Already covered | `resources/js/pages.test.ts` "fails loudly for a missing page". |
| 3. All `.vue` files linted | Already covered | `npm run lint` is `eslint .` over the whole repository (ignores only `public`, `vendor`, `node_modules`, `storage`, `bootstrap/ssr`). |
| 4. Browser-level CSP test | Deferred to P1-47 | Comes with the Playwright suite; the rendered-HTML check covers the server side now. |
| 5. Dependency-policy test | Not added | The two `npm audit` steps in `scripts/ci/frontend.sh` are the policy; their result is visible in every CI run. |
