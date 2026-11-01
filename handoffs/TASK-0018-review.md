# TASK-0018 — Codex verification of the TASK-0017 fixes (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (02:18:39–02:19:26 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a194e6-dc64-7ae2-a152-ff17d514ce6b |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0018.prompt.md`, branch `feature/p1-10-invitations` @ `d3c9519` |
| Invoked by | Claude Code, under the PO-approved working model ("next") |
| Raw output SHA-256 | `ab75e5487d6137034c8434b4d3e160d06b106ca858f329e61c304b89dc08df4b` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0017 dispositions

- **Finding 1 — Complete.** `InvitationService.php:51–53, 99–119` now requires an active inviter with `user.manage`, caps clearance, restricts non-super-admin role permissions, and limits `super_admin` assignment to super admins. The added tests cover seeded roles, custom roles, suspension, and clearance.

- **Finding 2 — Rejection sound.** `docs/07-security-threat-model.md` documents CI Director with `user.view` but without `user.manage`; the seeded matrix is consistent. No fix was required.

- **Finding 3 — Complete.** The route constraints were removed in `modules/Identity/routes/web.php:8–11`. Malformed tokens now reach `InvitationService::findOpen()` (`InvitationService.php:125–133`), which returns `null`, and the controller renders the same unavailable response. The added test covers malformed, uppercase, oversized, and unknown tokens.

- **Finding 4 — Complete.** `config/hashing.php:25–30` contains fixed Argon2id values with no environment overrides. Test-only reduction occurs in `tests/TestCase.php:33–35`.

## 2. New problems introduced

No new security or correctness problems were identified in the reviewed fix.

The route constraint removal does broaden the accepted path parameter, but malformed values are rejected before hashing or database lookup (`InvitationService.php:127–130, 140–145`) and remain rate-limited by the invitation middleware.

## 3. Recommendation

**Yes — safe to merge into `main`.**

## 4. What I did not check

I did not run tests, migrations, builds, static analysis, CI commands, network calls, or database queries. I reviewed the specified commit, requested files, prior review/disposition, relevant controller and invitation call sites, and read-only Git output only.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

No findings; recommendation "yes". Separately, CI #37 and #38 failed at the migration round-trip: spatie's permission migration clears its cache, the default cache is Valkey, and the CI backend job starts only MySQL. Fixed by running the round-trip with `CACHE_STORE=array` (reproduced locally with an unreachable Valkey: fails without, passes with). The role seed migration now also clears spatie's permission cache, because its raw inserts bypass spatie's model events.
