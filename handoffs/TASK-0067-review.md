# TASK-0067 — Codex review of P1-25a (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-03 |
| Codex | model gpt-5.6-luna, reasoning effort low |
| Session | 01a1a030-aaa1-7881-a80b-f7e5d6e50342 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0067.prompt.md`, branch `fix/registry-unique-violations` @ `d7deec1` (clean tree) |
| Invoked by | Claude Code, under the PO-approved working model |
| Raw output SHA-256 | `5d1d9c5406d36f99301210acf0d1502de07db545bd4686758419461821d7f42d` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — merge blocker**  
   **File:** `modules/Platform/src/Database/UniqueViolation.php:20–22`  
   **Problem:** The parser can match `for key '...'` inside the duplicate value rather than the actual index suffix. MySQL includes the duplicate value verbatim in the message.  
   **Scenario:** A duplicate value such as `Acme for key 'fake'` produces a message containing `for key 'fake' ... for key 'table.real_index'`; the regex captures `fake`, so the expected duplicate is rethrown instead of converted to the intended validation/idempotency result.  
   **Safe fix:** Parse all `for key '...'` occurrences and compare the final occurrence, or parse the index from the message suffix using an anchored pattern. Add a regression test with a duplicate value containing `for key '`.

2. **Medium — not a merge blocker**  
   **Files:** `modules/Platform/src/Database/UniqueViolation.php:20–22`; `modules/Registry/tests/Feature/UniqueViolationTest.php:74–87`  
   **Problem:** The helper is tested only against a live duplicate and does not test the documented older MySQL “bare index” format, malformed messages, or values containing quote-like/index-like text.  
   **Scenario:** A driver/server message format differs slightly, causing the helper to return `false` and expose a raw exception where the service contract expects a validation error.  
   **Safe fix:** Unit-test representative MySQL 8.4 and legacy messages, malformed/non-MySQL messages, table-qualified and bare index names, and adversarial duplicate values.

3. **Medium — not a merge blocker**  
   **File:** `modules/Platform/src/Outbox/OutboxRelay.php:138–144`  
   **Problem:** The new distinction is based solely on the index name. A handler that writes directly to `outbox_receipts` and raises the same unique-key violation could still be treated as a concurrent receipt and swallowed.  
   **Scenario:** A handler independently inserts a receipt for the current event/handler, then the relay’s receipt insert collides on `outbox_receipts_outbox_event_id_handler_unique`; the relay treats it as successful even though the handler may not have completed its intended work.  
   **Safe fix:** Make receipt insertion exclusively relay-owned by contract and enforce/test that handlers cannot write receipt rows, or distinguish the relay receipt insert from handler work structurally (for example, isolate receipt insertion or use an explicit handler result protocol).

4. **Low — not a merge blocker**  
   **File:** `modules/Platform/src/Audit/AuditCheckpointer.php:63–70`  
   **Problem:** The implementation correctly narrows handling to the two intended indexes, but there is no focused test proving that an unrelated unique violation during checkpoint creation is rethrown.  
   **Scenario:** A future unique constraint is added to `audit_checkpoints`; an insert fails on that constraint and a later refactor accidentally treats it as a harmless concurrent checkpoint.  
   **Safe fix:** Add a test that forces an unrelated checkpoint duplicate and asserts the original `UniqueConstraintViolationException` escapes.

## Missing tests

- `UniqueViolation::on()` with a duplicate value containing `for key '...'`.
- `UniqueViolation::on()` with:
  - table-qualified index;
  - bare index;
  - legacy message format;
  - malformed message;
  - wrong table/index;
  - multiple `for key` phrases.
- Every changed Registry service’s unrelated-duplicate path, not only currency/category/tag audit ULID cases.
- Competitor creation where a non-slug unique violation occurs: it must not consume a slug retry.
- Audit checkpoint unrelated unique violation must rethrow.
- Outbox handler duplicate caused by handler-owned database work must retry/dead-letter and must not create a receipt.
- Outbox concurrent receipt collision must preserve the expected “already handled” behavior and confirm handler-side transaction changes are rolled back.
- A full Registry concurrency run specifically asserting no raw unexpected duplicate errors after narrowing.

## Recommendation

**Yes after fixes.**

The index mappings appear consistent with the inspected migrations, and rethrowing from the transaction callbacks should preserve Laravel rollback behavior. However, the message parser has a concrete correctness gap for duplicate values containing `for key '`, which can break the stated guarantee that only the expected duplicate is translated.

## Unresolved assumptions / questions

- Are handlers formally prohibited from inserting into `outbox_receipts` themselves? If so, that contract should be documented and tested.
- Is support for legacy MySQL error-message formats required operationally, or is MySQL 8.4 the only supported runtime?
- Should the helper be explicitly MySQL-only, or must it remain portable across database drivers?

## What I did not check

- I did not run tests, static analysis, Pint, migrations, or the full suite; the review was restricted to read-only inspection as requested.
- I did not inspect `.env`, other ignored environment files, or `.secrets/`.
- I did not verify the live database index names independently; I compared the code against the repository migrations and the brief’s reported database check.
- I did not review unrelated files outside the requested diff and supporting files.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-03)

Recommendation "yes after fixes"; fixed in the next commit. No credential file was opened (Codex's log).

| Finding | Disposition |
|---|---|
| 1. High: a duplicate value containing `for key '…'` can steer the parser | **Fixed.** `UniqueViolation::on()` reads only the driver's own message (`errorInfo[2]`, never the exception message, which adds the SQL) and only the key at its very end (`/ for key '([^']+)'\z/`), so text inside the quoted value cannot be taken for the key. Unit tests with a value that imitates an expected key. |
| 2. Medium: parser formats untested | **Fixed.** MySQL 8.4 is the only supported runtime (ADR-0003), so the helper now accepts only its table-qualified form; the bare index of older servers, other drivers' messages, empty and missing messages never match, so the original error surfaces. Unit tests for each (`Modules\Platform\Tests\Unit\UniqueViolationTest`). |
| 3. Medium: a handler writing a receipt could still be swallowed | **Fixed by contract and a code rule.** Only the relay writes receipts: stated in `OutboxHandler`, and `CodeRulesTest` ("only the outbox relay names the receipts table") fails if any other file in `modules/*/src`, `routes`, `app` names the table (a tripwire; a table name built at run time is a code-review matter, as for the other table rules). |
| 4. Low: no test that an unrelated checkpoint duplicate is rethrown | **Not added: it cannot happen today.** `audit_checkpoints` has no unique key besides the two expected ones and its auto-increment primary key; a future key would be added with its own migration and review. The narrowed catch is covered by `AuditTrailTest`'s concurrent-checkpoint test. |
| Missing tests | Added: the parser cases above; the forced-duplicate test now covers seven services (currency, category, tag, channel, brand, market, competitor) and checks that the competitor slug retry does not consume its attempts on another key (no row created). Already covered: the concurrent receipt collision keeps "already handled" with the handler's changes rolled back (`OutboxTest`'s raced-handler test); a handler duplicate fails and is retried with no receipt (`test_a_duplicate_raised_by_the_handler_itself_fails_the_delivery`). Next full runs: whether the unexplained registry failure reappears, now with the real key named. |
