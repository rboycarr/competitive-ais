# Handoffs — Claude ↔ Codex Operating Guide

Protocol: ADR-0009. Ledger: `docs/12-claude-codex-handoffs.md`.

## Files per task
| File | Written by | Content |
|---|---|---|
| `TASK-####.md` | The implementer (Codex from 2026-11-03; Claude Code before) | Brief: objective, context, branch, affected files, constraints, acceptance tests, definition of done, review focus, commands run with results |
| `TASK-####.prompt.md` | The implementer | The exact prompt to give the agent (plain text, no secrets) |
| `TASK-####-review.md` | The reviewer's output verbatim (Claude Code from 2026-11-03; Codex before), then the implementer's disposition table | Findings, and the accept/reject/defer decision per finding, with evidence and commit references |

Task numbers are sequential and never reused.

## Session handoffs
`SESSION-YYYY-MM-DD.md` (Claude Code, when the PO asks): context for Codex's next review rounds. It covers the project state, what changed in the session, the decisions in force, the conventions earlier reviews established, known gaps, and what comes next. No secrets, ever. Codex reads the latest one before a review when its brief points to it.

## Running Codex as the implementer (from 2026-11-03)

Codex now implements (`AGENTS.md`). **Read-only mode blocks it**: it cannot create a branch, edit files, or run the tests.
- **VS Code Codex panel (recommended):**
  1. Open this folder and start a new task in **Agent** mode, which lets Codex write in the workspace and asks before other actions. Not "Chat" or read-only.
  2. Paste the contents of the task's prompt, e.g. `handoffs/TASK-0069.prompt.md`.
  3. Approve its `wsl … docker compose …` and `git push` commands when it asks. Codex asks the PO its product questions in the same conversation.
- **CLI, interactive** (not `exec`, so that it can ask questions):
  ```powershell
  & $codex --sandbox workspace-write --ask-for-approval on-request -C $repo
  ```
  Then paste the prompt.
- **If the sandbox blocks the WSL and Docker commands** the stack needs: approve them when asked. Granting full access (`--sandbox danger-full-access`) is a decision for the PO alone, and `AGENTS.md`'s rules still apply either way.
- **Reviews of Codex's branches:** Claude Code reviews them read-only, from the brief Codex writes (`handoffs/TASK-####.md` and `.prompt.md`).

## Running Codex as a reviewer (read-only; how TASK-0001 to TASK-0068 were run)

**Option A — VS Code Codex panel:** open this folder, start a new Codex task, paste the contents of `TASK-####.prompt.md`, set the mode to read-only / ask for approval, and save the final answer to `TASK-####-review.md`.

**Option B — CLI (PowerShell):**
```powershell
# Locate the Codex binary bundled with the VS Code extension (not on PATH)
$codex = (Get-ChildItem "$env:USERPROFILE\.vscode\extensions\openai.chatgpt-*\bin\windows-x86_64\codex.exe" |
          Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName
$repo  = (git rev-parse --show-toplevel)   # run from inside the working copy
$task  = "TASK-0001"

# Read-only sandbox; prompt from file via stdin; final message saved to the review file
Get-Content "$repo\handoffs\$task.prompt.md" -Raw |
  & $codex exec -s read-only -C $repo --skip-git-repo-check -o "$repo\handoffs\$task-review.md" -
```
- `--skip-git-repo-check` is needed only until the repository is initialized with git (Sprint 1.0).
- For diff reviews once git exists: `& $codex exec review --base main` (or `--uncommitted`) from the repo, with the review prompt as the argument.
- Add a first line to the review file with the date, `codex --version`, and the model used, so the record is traceable.
- If you ask Claude to run the review for you, Claude uses this same read-only command, writes the raw output to a temporary file, and wraps it unedited between `BEGIN/END CODEX OUTPUT` markers with a header (date, version, model, session ID, SHA-256 of the raw output). TASK-0001 was run this way.

## Rules
- One implementer per task. The reviewer never edits the implementer's branch. From 2026-11-03 Codex implements on `feature/<story>` (or `fix/` and `docs/`) branches in this working copy, and Claude Code only reviews.
- Never put secrets, `.env` contents, credentials, real evidence, or personal data into a brief or prompt. Codex is a cloud service; the repo is synthetic-data-only.
- A commit may carry the trailer `Codex-Review: handoffs/TASK-####-review.md` **only** if that file exists with real Codex output.
- Disagreements are decided on evidence (tests, reproduction). High-risk disagreements are escalated to the Product Owner and logged in `docs/12` §4.

## Disposition table format (appended by Claude)
```markdown
## Disposition (Claude Code, YYYY-MM-DD)
| # | Finding (short) | Severity | Decision | Evidence / rationale | Commit / doc change |
|---|---|---|---|---|---|
```
