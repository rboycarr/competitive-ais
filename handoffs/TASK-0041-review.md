# TASK-0041 — Codex verification of the TASK-0040 dispositions (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (01:08:34–01:09:16 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a199cd-0d7c-7a12-b2fc-0607cedb4cbf |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0041.prompt.md`, branch `feature/p1-23a-markets-regions` @ `a4589d0` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `a12e4bef7e65b1abb0ed8d8293a64e7ad6e9ae586ff825b69385410a032d4191` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## TASK-0040 dispositions

1. **Market-code immutability — Complete and correct.**  
   The deferral is sound: current and planned market-grant references use `market_id`, not `markets.code` (`docs/02-database-erd.md:149-150, 174, 213`; `docs/11-delivery-roadmap.md:109`). P1-52 explicitly covers database enforcement and direct-update tests for all documented fixed fields.

2. **Unclassified-table diagnostic — Complete and correct.**  
   `TableGrants` now derives the message from `TableGrants::CLASSES` (`modules/Platform/src/Database/TableGrants.php:128`), and the test asserts all five classes (`modules/Platform/tests/Feature/DatabaseAccountsTest.php:255`).

The added read-only `INSERT … SELECT` checks and unknown-market 404 assertion are correctly scoped (`DatabaseAccountsTest.php:360`; `MarketTest.php:54`).

## New problems

None identified in `git diff e8f144b..HEAD`. The changes do not weaken authorization, grant enforcement, provenance, or database integrity.

## Recommendation

**Yes — safe to merge into `main`.**

## What I did not check

- I did not execute tests, static analysis, migrations, CI, or database-account probes.
- I did not verify the CI results reported in TASK-0041.
- I did not validate the eventual P1-52 implementation, since it is deferred.
- I did not inspect unrelated pre-existing code beyond the files and references needed for this review.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

Both TASK-0040 dispositions confirmed, no new problems; recommendation "yes". P1-52 (database-enforced immutability of fixed fields) is in docs/11, Sprint 1.5.
