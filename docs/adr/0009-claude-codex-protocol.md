# ADR-0009 — Claude ↔ Codex collaboration through manual handoffs

- **Status:** Accepted, 2026-10-31 (Product Owner decision D4)

## Context
`competitive-ais.md` §16 asks for Claude Code and OpenAI Codex to cooperate through explicit artifacts and independent reviews, never through imagined conversations. Codex CLI is installed (bundled with the VS Code ChatGPT extension) and logged in. The Product Owner chose to run Codex personally rather than let Claude invoke it.

## Decision
- Claude Code writes `handoffs/TASK-####.md` (brief) and `TASK-####.prompt.md` (exact prompt). The user runs Codex (VS Code panel or CLI, read-only sandbox) and saves the output verbatim to `TASK-####-review.md`. Claude appends a disposition for every finding.
- Codex review is mandatory for migrations, authorization, evidence handling, collectors, AI tools, and voice actions (`docs/12` §2).
- One implementer per task; separate branches/worktrees if Codex is assigned implementation.
- No secrets or real data in prompts. The repository holds synthetic data only.
- `AGENTS.md` gives Codex its standing instructions.
- A commit trailer `Codex-Review:` is allowed only when the review file exists with real output. AI review never substitutes for human approval.

- **Amended 2026-10-31:** the Product Owner may ask Claude to run a specific review. Claude then uses the same read-only command and records in the review header that it was invoked at the PO's request. The default remains manual.

## Consequences
- Full human control over what is sent to Codex and when.
- Review latency depends on the user. Gated merges wait for it.
- Traceability lives in `handoffs/` and the ledger in `docs/12`.

## Alternatives considered
- Claude invokes Codex read-only automatically: faster loop; declined by the Product Owner.
- Codex as a full co-implementer: more parallelism; more coordination risk at this stage.
