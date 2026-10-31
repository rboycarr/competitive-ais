# Handoffs — Claude ↔ Codex Operating Guide

Protocol: ADR-0009. Ledger: `docs/12-claude-codex-handoffs.md`.

## Files per task
| File | Written by | Content |
|---|---|---|
| `TASK-####.md` | Claude Code | Brief: objective, context, branch, affected files, constraints, acceptance tests, definition of done, review focus, commands Claude ran with results |
| `TASK-####.prompt.md` | Claude Code | The exact prompt to give Codex (plain text, no secrets) |
| `TASK-####-review.md` | **The user** pastes Codex output verbatim; Claude Code appends the disposition table | Codex findings + Claude's accept/reject/defer decision per finding, with evidence and commit references |

Task numbers are sequential and never reused.

## Running Codex (user)

**Option A — VS Code Codex panel:** open this folder, start a new Codex task, paste the contents of `TASK-####.prompt.md`, set the mode to read-only / ask for approval, and save the final answer to `TASK-####-review.md`.

**Option B — CLI (PowerShell):**
```powershell
# Locate the Codex binary bundled with the VS Code extension (not on PATH)
$codex = (Get-ChildItem "$env:USERPROFILE\.vscode\extensions\openai.chatgpt-*\bin\windows-x86_64\codex.exe" |
          Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName
$repo  = "D:\xampp_lite_8_3\www\competitive-ais"
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
- One implementer per task. Codex never edits Claude's active branch. When Codex is assigned implementation, it works on `review/TASK-####` or `feature/<epic>-<name>-codex` in a separate worktree.
- Never put secrets, `.env` contents, credentials, real evidence, or personal data into a brief or prompt. Codex is a cloud service; the repo is synthetic-data-only.
- A commit may carry the trailer `Codex-Review: handoffs/TASK-####-review.md` **only** if that file exists with real Codex output.
- Disagreements are decided on evidence (tests, reproduction). High-risk disagreements are escalated to the Product Owner and logged in `docs/12` §4.

## Disposition table format (appended by Claude)
```markdown
## Disposition (Claude Code, YYYY-MM-DD)
| # | Finding (short) | Severity | Decision | Evidence / rationale | Commit / doc change |
|---|---|---|---|---|---|
```
