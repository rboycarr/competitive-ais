# TASK-0043 — Codex verification of the TASK-0042 dispositions (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-02 (03:29:29–03:30:44 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19a4e-1310-7160-a073-7489cdaac1d9 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0043.prompt.md`, branch `feature/p1-23b-channels-retailers-tags` @ `c012498` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model (authorization, schema and grants: review mandatory) |
| Raw output SHA-256 | `1d1c0bafea55f593213793480037c201e0be81d8904efb6c6bbdc40ae2aadfa8` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## 1. TASK-0042 dispositions

### #1 — Tag catalog disclosure: confirmed

- `modules/Registry/src/Http/Controllers/CompetitorController.php:79-80`
- `modules/Registry/src/Http/Controllers/CompetitorController.php:107-109`

List filters now expose only tags attached to competitors returned by `Competitor::visibleTo($viewer)`. Profiles expose the full catalog only to `competitor.manage` users; readers receive `[]`.

No concrete failure found in the changed code. A reader viewing a visible competitor cannot receive tags belonging only to hidden competitors or unused tags through these responses.

The added test at `modules/Registry/tests/Feature/TagTest.php:137-153` covers the stated cases.

### #2 — Direct SQL tagging boundary: partially confirmed

- `tests/Architecture/CodeRulesTest.php:24-40`

The rule currently detects all existing literal `taggables` references under the scanned runtime directories and requires the current three-file allowlist. Current production references are limited to the intended service, controller, and model.

However, the rule is not comprehensive. A future file could bypass it with a dynamically constructed name such as:

```php
DB::table('tag' . 'gables')->insert(...);
```

That would still access the table but would not match `str_contains(..., 'taggables')`. The present branch contains no such bypass, so the disposition is confirmed for the current codebase, but the architecture guarantee is weaker than stated.

### Missing-test dispositions

The changed files do not contradict the recorded dispositions. The concurrency cases remain explicitly deferred to P1-25. The tag-option test was added, and the other cited coverage is represented in the prior review record.

## 2. New finding

### Medium — Architecture rule can be bypassed by dynamic table references

**File:** `tests/Architecture/CodeRulesTest.php:34`

**Problem:** The rule relies on a literal substring search for `taggables`. It does not prove that all runtime database access to the table is represented in the allowlist.

**Failure scenario:** A future controller, job, command, or service executes `DB::table('tag'.'gables')` or obtains the table name from a constant/config value. The architecture test passes even though the new code bypasses the reviewed access boundary and could write links without `AccessScope`.

**Safe fix:** Replace the substring scan with an AST/token-based check that resolves literal concatenations and detects table references passed to query-builder methods. Preferably also centralize taggable reads and writes behind reviewed repository/service methods, with an architecture rule forbidding raw `DB::table()` access to this table outside those methods.

## 3. Recommendation

**Yes after fixes.**

The branch does not currently introduce a demonstrated unsafe runtime path and is not less safe than `main`. The architecture rule weakness should be fixed before relying on it as the stated long-term guard.

## 4. What I did not check

- I did not run tests, static analysis, migrations, builds, or application commands.
- I did not execute database queries or verify MySQL behavior.
- I did not inspect unrelated files outside the branch diff in full.
- I did not independently verify the prior review’s reported test results.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-02)

| # | Finding | Disposition |
|---|---|---|
| TASK-0042 #1 | Confirmed | — |
| TASK-0042 #2 / new medium | The architecture rule is a substring match; a table name built at run time (`'tag'.'gables'`, a constant, config) escapes it | **Claim corrected, not re-engineered.** The rule's comment and docs/02 now say what it is: a tripwire for ordinary use, not a proof. An AST check would resolve literal concatenation but still miss names taken from constants, config or variables, so no static rule can give the stated guarantee. The same bypass exists for every table the app account can write, so it is not specific to `taggables`. The controls remain `CompetitorTagService` (the only writer, checking `AccessScope`) and code review. Nothing in the branch uses such a bypass (Codex confirmed). |

No further Codex round: this commit changes only a comment and documentation wording. Recommendation recorded as "yes after fixes"; the remaining fix is this wording.
