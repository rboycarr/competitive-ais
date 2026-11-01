# TASK-0020 — Codex verification of the TASK-0019 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (03:21:05–03:21:57 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19520-0894-7b40-a45a-7550561cd01e |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0020.prompt.md`, branch `feature/p1-11-login-mfa` @ `341be2d` |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `7772baeeeef5340c85073b5bb660fe693eb6ca0840d826b54443f424634b8d46` |

Everything between the markers below is Codex's final message, unedited. (Its file links point at the local checkout.)

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0019 findings

1. **Complete — timing-based account enumeration**

   Evidence: [`AuthenticateUser.php:27–30`](D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/src/Actions/AuthenticateUser.php:27) always performs exactly one hash check. Unknown users use `DUMMY_HASH`; existing inactive users use their stored hash; all are rejected after verification.

   Supporting test: [`LoginTest.php:76–91`](D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/tests/Feature/LoginTest.php:76).

2. **Complete — global per-account throttling**

   Evidence: [`IdentityServiceProvider.php:94–97`](D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/src/IdentityServiceProvider.php:94) defines:

   - 5/minute per account and IP
   - 10/minute per normalized account across IPs
   - 30/minute per IP

   Supporting distributed-IP test: [`LoginTest.php:112–125`](D:/xampp_lite_8_3/www/competitive-ais/modules/Identity/tests/Feature/LoginTest.php:112).

## 2. New problems introduced

No new security or engineering problems attributable to this fix were identified.

The implementation preserves the accepted behavior that inactive accounts perform a real stored-hash check, while unknown accounts use the configured-cost dummy hash.

## 3. Recommendation

**Yes.** The fix addresses both TASK-0019 findings without introducing a regression that makes the branch less safe than `main`.

## 4. What I did not check

- I did not execute tests, CI, Composer, migrations, or the application.
- I did not inspect unchanged Fortify/vendor implementation.
- I did not independently verify runtime middleware registration or production rate-limiter storage.
- I did not review unrelated files outside the requested commit and files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Both fixes complete, no new findings, recommendation "yes". Ready for the Product Owner to merge once CI is green. Open decision for the PO (recorded in `docs/02` §3.2): the per-account limit lets an attacker keep one account throttled while they keep trying.
