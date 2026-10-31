# 12 — Claude ↔ Codex Collaboration Ledger

| Field | Value |
|---|---|
| Status | Active from 2026-10-31 |
| Owner | Claude Code (maintains); Product Owner (runs Codex, approves) |
| Protocol | ADR-0009; operating guide in `handoffs/README.md` |

## 1. Operating model (decision D4: manual handoff)
- **Claude Code:** lead architect, primary implementer, and maintainer of the source-of-truth docs. Writes task briefs, implements, runs tests, triages review findings.
- **OpenAI Codex:** independent reviewer and adversarial-test proposer. Implements only bounded tasks the user explicitly assigns, on its own branch or worktree.
- **The user (Product Owner):** the only party who runs Codex, and the only party who approves merges for gated changes.
- By default Claude does **not** invoke Codex. The exception is when the Product Owner explicitly asks Claude to run a specific review in that session. The review file then records "invoked by Claude at the Product Owner's request", and the run is still read-only. Claude never states that Codex reviewed something unless `handoffs/TASK-####-review.md` contains Codex's actual output.

**Environment fact (2026-10-31):** Codex CLI `0.164.0-alpha.11.1` is bundled with the VS Code ChatGPT extension (`%USERPROFILE%\.vscode\extensions\openai.chatgpt-*\bin\windows-x86_64\codex.exe`). It is not on PATH. It is logged in through ChatGPT. This project is not in Codex's trusted-project list.

## 2. When Codex review is required

| Change type | Codex review | Human approval to merge |
|---|---|---|
| Migrations / schema | **Mandatory** | **Required** |
| Auth, policies, AccessScope, MFA, grants | **Mandatory** | **Required** |
| Evidence and file handling | **Mandatory** | Required |
| Collectors, SSRF, worker contracts [P3] | **Mandatory** | **Required** (new collection) |
| AI tools, prompts, retrieval [P4]; voice actions [P5] | **Mandatory** | Required |
| Dependencies added or upgraded | Recommended | **Required** |
| UI-only, docs-only | Optional | Normal review |
| Production release | Summary review recommended | **Required** |

AI approval never replaces human approval.

## 3. Task ledger

| Task | Title | Implementer | Codex role | Branch | Status | Review record |
|---|---|---|---|---|---|---|
| TASK-0001 | Phase 0 architecture and docs review | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-10-31) | n/a (repo not yet in git) | **Reviewed; 13 accepted, 1 partially accepted; docs amended** | `handoffs/TASK-0001-review.md` |
| TASK-0002 | Follow-up review of the TASK-0001 amendments | Claude Code | Reviewer | n/a | Proposed (not yet created) | — |
| TASK-0003 | **Retrospective** review: DB grants (P1-09), object-store keys, CI | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-10-31) | reviewed `main` @ `28ca5c7`; fixes on `fix/task-0003-hardening` | **Reviewed: 10 findings (1 critical, 3 high, 5 medium, 1 low); 8 accepted, 2 accepted in part; fixes implemented.** Pending: Codex re-review of the fix diff (TASK-0004) and PO approval to merge. G1 not signed off until merged | `handoffs/TASK-0003-review.md` |
| TASK-0004 | Re-review of the TASK-0003 fixes (diff `main..fix/task-0003-hardening`) | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-10-31) | `fix/task-0003-hardening` @ `64c1c60` | **Reviewed: 4 fixes complete, 5 partial, 3 new findings (2 high, 1 medium); all accepted and fixed on the same branch.** | `handoffs/TASK-0004-review.md` |
| TASK-0005 | Re-review of the TASK-0004 fixes (diff `64c1c60..HEAD`) | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-11-01) | `fix/task-0003-hardening` @ `c407212` | **Reviewed: N1, N2, N3 and init-env complete; 2 new findings (1 high, 1 medium), recommendation "no" until fixed; both accepted and fixed.** Merge to `main` approved by the PO (2026-11-01) subject to this review | `handoffs/TASK-0005-review.md` |
| TASK-0006 | Verification of the TASK-0005 fix commit `41e748a` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `41e748a` | **Reviewed: both fixes partial; 6 findings (1 high, 2 medium, 3 low), recommendation "no"; all accepted and fixed** | `handoffs/TASK-0006-review.md` |
| TASK-0007 | Verification of the TASK-0006 fix commit `2a58cac` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `2a58cac` | **Reviewed: TASK-0006 fixes complete; 2 high (managed-database scope), 2 medium; recommendation "no". 3 accepted and fixed, 1 medium rejected with rationale** | `handoffs/TASK-0007-review.md` |

## 4. Decision and disagreement log

| Date | Topic | Claude position | Codex position | Evidence | Decision | Decided by |
|---|---|---|---|---|---|---|
| 2026-10-31 | Phase 1 schedule (TASK-0001 #13) | Keep the minimal dashboard in Phase 1 (required by `skills/10`, `CLAUDE.md` §10) | Move nonessential dashboard/search after the security foundation | Project rules list the dashboard as a Phase 1 deliverable | Dashboard moved to the final sprint 1.5 after the security gates; quick search made a stretch item; estimate widened to 8–14 weeks | Claude Code; **PO confirmation pending (O16)** |
| 2026-10-31 | Scope matching semantics (TASK-0001 #1) | All-of, so scope inheritance only tightens access | Asked for an explicit evidence visibility model | Any-of + inheritance would widen access | All-of adopted as the default | Claude Code; **PO confirmation pending (O10)** |

## 5. Doc-alignment checklist (run at every phase gate)

- [x] `docs/00` decisions D1–D4 match ADR-0002, 0003, 0008, 0009
- [x] Every Phase 1 table in `docs/02` has an owning module in `docs/03`
- [x] Every Phase 1 permission in `docs/03` appears in the role → permission matrix in `docs/07` §3
- [x] Append-only tables marked in `docs/02` §3 match the grant list in `docs/02` §6
- [x] Backlog IDs in `docs/11` cover every Phase 1 item in `docs/03`
- [x] Codex review of Phase 0 recorded (TASK-0001, 2026-10-31)
- [ ] Codex follow-up review of the amendments (TASK-0002)
- [ ] PO answers to O10–O16
- [x] O1 answered (GitHub + Actions, 2026-10-31)
- [ ] O2 answered before Sprint 1.2
- [x] O17 answered (SeaweedFS, 2026-10-31)
