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
| TASK-0003 | **Retrospective** review: DB grants (P1-09), object-store keys, CI | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-10-31) | reviewed `main` @ `28ca5c7`; fixes on `fix/task-0003-hardening` | **Reviewed: 10 findings (1 critical, 3 high, 5 medium, 1 low); 8 accepted, 2 accepted in part; fixes implemented.** Fixes re-reviewed in TASK-0004 to TASK-0009 and merged into `main` 2026-11-01 | `handoffs/TASK-0003-review.md` |
| TASK-0004 | Re-review of the TASK-0003 fixes (diff `main..fix/task-0003-hardening`) | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-10-31) | `fix/task-0003-hardening` @ `64c1c60` | **Reviewed: 4 fixes complete, 5 partial, 3 new findings (2 high, 1 medium); all accepted and fixed on the same branch.** | `handoffs/TASK-0004-review.md` |
| TASK-0005 | Re-review of the TASK-0004 fixes (diff `64c1c60..HEAD`) | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-11-01) | `fix/task-0003-hardening` @ `c407212` | **Reviewed: N1, N2, N3 and init-env complete; 2 new findings (1 high, 1 medium), recommendation "no" until fixed; both accepted and fixed.** Merge to `main` approved by the PO (2026-11-01) subject to this review | `handoffs/TASK-0005-review.md` |
| TASK-0006 | Verification of the TASK-0005 fix commit `41e748a` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `41e748a` | **Reviewed: both fixes partial; 6 findings (1 high, 2 medium, 3 low), recommendation "no"; all accepted and fixed** | `handoffs/TASK-0006-review.md` |
| TASK-0007 | Verification of the TASK-0006 fix commit `2a58cac` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `2a58cac` | **Reviewed: TASK-0006 fixes complete; 2 high (managed-database scope), 2 medium; recommendation "no". 3 accepted and fixed, 1 medium rejected with rationale** | `handoffs/TASK-0007-review.md` |
| TASK-0008 | Verification of the TASK-0007 fix commit `55c7e53` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `55c7e53` | **Reviewed: TASK-0007 fixes complete; 1 high (test round-trip changed main-database grants), recommendation "no"; accepted and fixed** | `handoffs/TASK-0008-review.md` |
| TASK-0009 | Verification of the TASK-0008 fix commit `ae4ab6f` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's 2026-11-01 pre-merge review approval) | `fix/task-0003-hardening` @ `ae4ab6f` | **Reviewed: fix complete, no new findings, recommendation "yes". Merged into `main` (PO approval 2026-11-01). TASK-0003 to TASK-0009 closed** | `handoffs/TASK-0009-review.md` |
| TASK-0010 | Review of P1-05 (audit trail), diff `main..feature/p1-05-audit` | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-11-01) | `feature/p1-05-audit` @ `bd773f1` | **Reviewed: 2 high, 2 medium; recommendation "no". #1, #2 accepted and fixed; #3 documentation corrected (code change rejected with rationale); #4 deferred to P1-50.** Fixes verified in TASK-0011 | `handoffs/TASK-0010-review.md` |
| TASK-0011 | Verification of the TASK-0010 fix commit `49e54b3` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's review request) | `feature/p1-05-audit` @ `49e54b3` | **Reviewed: #2 complete, #1 partial (by design, wording fixed), #3/#4 rationale sound; 1 medium, 1 low; recommendation "yes after fixes"; both fixed.** Merged into `main` 2026-11-01 (PO approval) | `handoffs/TASK-0011-review.md` |
| TASK-0012 | Review of P1-06 (outbox), diff `main..feature/p1-06-outbox` | Claude Code | Reviewer (gpt-5.6-luna, read-only; invoked by Claude at the PO's request, 2026-11-01) | `feature/p1-06-outbox` @ `e401466` | **Reviewed: 1 high, 1 medium, 1 low; recommendation "no"; all accepted and fixed.** Fixes verified in TASK-0013 | `handoffs/TASK-0012-review.md` |
| TASK-0013 | Verification of the TASK-0012 fix commit `a3dfb75` | Claude Code | Reviewer (gpt-5.6-luna, read-only; under the PO's review request) | `feature/p1-06-outbox` @ `a3dfb75` | **Reviewed: all 3 fixes complete; 1 low (stale annotation) fixed; recommendation "yes, after fixes".** Merged into `main` as pull request #1 (PO, 2026-11-01) | `handoffs/TASK-0013-review.md` |
| TASK-0014 | Review of P1-08 (secure HTTP defaults), diff `main..feature/p1-08-secure-defaults` | Claude Code | Reviewer (gpt-5.6-luna, read-only; PO-approved working model) | `feature/p1-08-secure-defaults` @ `f3e3853` | **Reviewed: 1 high, 1 medium, 1 low; recommendation "yes after fixes"; all accepted and fixed.** Fixes verified in TASK-0015 | `handoffs/TASK-0014-review.md` |
| TASK-0015 | Verification of the TASK-0014 fix commit `f234de7` | Claude Code | Reviewer (gpt-5.6-luna, read-only; PO-approved working model) | `feature/p1-08-secure-defaults` @ `f234de7` | **Reviewed: all 3 fixes complete, no new findings, recommendation "yes".** Merged into `main` as pull request #2 (PO, 2026-11-01) | `handoffs/TASK-0015-review.md` |
| TASK-0016 | Review of the frontend shell, diff `main..feature/frontend-shell` | Claude Code | Reviewer (gpt-5.6-luna, read-only; PO-approved working model) | `feature/frontend-shell` @ `9b998d3` | **Reviewed: no findings, recommendation "yes"; one suggested test added.** Merged into `main` as pull request #3 (PO, 2026-11-02) | `handoffs/TASK-0016-review.md` |

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
