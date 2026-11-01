# TASK-0019 — Codex review of P1-11 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (02:58:24–02:59:50 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a1950b-47fd-7341-a9ae-fb9fbb5f71ab |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0019.prompt.md`, branch `feature/p1-11-login-mfa` @ `3efe41f` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (auth: review mandatory) |
| Raw output SHA-256 | `26638334f205d0abeef755c1d48755bc3f176206fbc07b5b4454bbf5decbe473` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — timing-based account enumeration**

   **File:** `modules/Identity/src/IdentityServiceProvider.php:77–82`

   Unknown and inactive accounts return before `Hash::check()`, while active accounts perform Argon2id verification. An attacker can distinguish active, suspended, deactivated, and nonexistent addresses by response timing even though the error message is identical.

   **Safe fix:** Always perform a password-hash verification, including against a constant dummy Argon2id hash, for nonexistent and inactive accounts. Return the same response and status for every failure. Add timing-tolerance tests or benchmarks.

2. **Medium — login throttling is not globally per account**

   **File:** `modules/Identity/src/IdentityServiceProvider.php:85–97`

   The “account” limiter key is `email|IP`, so an attacker can bypass the five-attempt account limit by rotating source IPs. The implementation provides five attempts per account/IP, plus 30 per IP, but not five attempts per account as the review brief may imply.

   **Failure scenario:** An attacker distributes password guesses for one known account across multiple IPs; each IP gets a fresh five-attempt account bucket.

   **Safe fix:** Add a separate normalized-account limiter independent of IP, while retaining the per-IP limiter. Define the expected lockout policy explicitly so distributed attacks do not create an unacceptable victim lockout.

## Missing tests

- Timing-equivalence coverage for unknown, inactive, and active/wrong-password accounts.
- Suspended and deactivated accounts explicitly rejected with the same status, message, and observable behavior.
- Distributed-IP attempts proving the intended account-level throttling semantics.
- Session ID regeneration after password login, MFA completion, and logout; remember-me behavior and cookie invalidation.
- Direct access to every Fortify two-factor endpoint without authentication and without recent password confirmation.
- MFA challenge replay, expired/missing pending-login state, cross-session challenge use, and concurrent recovery-code redemption.
- Secret/recovery-code absence from JSON responses, Inertia props, audit rows, and application logs.
- Recovery-code regeneration invalidating all previous codes.
- Route tests confirming registration, reset, password-update, email-verification, and passkey surfaces remain unavailable.

## Recommendation

**Yes after fixes.** The branch should not merge until the timing-based account enumeration issue is addressed. The throttling behavior also needs either a global account limiter or an explicit Product Owner decision that account-plus-IP throttling is intentional.

## Unresolved assumptions / questions

- Is the intended login limit five attempts globally per account, or five attempts per account/IP?
- Is a small timing difference between unknown, inactive, and active accounts considered acceptable? The documented guarantee suggests it is not.
- Are Fortify’s generated two-factor routes guaranteed to retain `auth` and recent `password.confirm` middleware in this exact configuration?

## What I did not check

- I did not execute tests, CI scripts, migrations, Composer, npm, or the application.
- I did not make network calls or independently verify dependency advisories.
- I did not inspect unchanged Fortify vendor implementation beyond the changed configuration and application integration.
- The CI results in the brief are reported by Claude Code; I did not independently reproduce them.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Timing reveals unknown and inactive accounts | High | **Accept** | `Modules\Identity\Actions\AuthenticateUser` runs exactly one Argon2id check per attempt: against the account's hash when it exists (also when inactive) and against a fixed dummy hash with the production parameters otherwise; status is checked after. Tests: one hash check for unknown, suspended, deactivated and wrong-password attempts (counting hasher), the dummy only for unknown accounts; the dummy's parameters equal those in `config/hashing.php`. |
| 2 | Account limit keyed on email + IP, bypassable by rotating IPs | Medium | **Accept** | Limits are now 5/min per account+IP, **10/min per account across all IPs**, and 30/min per IP; every refusal is audited. Trade-off for the PO: an attacker can keep one account throttled while they keep trying (one-minute window, visible in the audit trail). Test: ten guesses from ten IPs, then the right password from an eleventh IP is refused. |
| Missing tests | — | — | **Accept in part** | Added: deactivated account, session regeneration on login, two-factor endpoints refuse guests, regenerating recovery codes invalidates the old ones, distributed throttling, timing parity. Already covered: identical failure message, code replay (next-window code after confirmation), recovery code single use, secrets absent from props and audit, disabled Fortify routes. Fortify's two-factor routes carry `auth` and `password.confirm` (tested: guests and unconfirmed sessions are refused). |
