# TASK-0012 — Codex review of P1-06 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (22:26:12–22:26:46 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a19412-0d69-7e42-bcd5-dc5179a73a82 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0012.prompt.md`, branch `feature/p1-06-outbox` @ `e401466` (clean tree) |
| Invoked by | Claude Code, **at the Product Owner's request** ("proceed. merge. go") |
| Raw output SHA-256 | `21f6078faa319f49812e81c88688069e56268bf354a8243b1e152aef93eb4e24` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — Outbox events with no handlers are silently marked dispatched**  
   File: `modules/Platform/src/Outbox/OutboxRelay.php:98-108`

   If an event is registered but no handler is subscribed, `handlers()` returns an empty list. The relay then sets `dispatched_at` without creating any receipt. A misconfigured provider can therefore permanently lose an event while reporting successful delivery.

   **Scenario:** `OutboxPublisher` writes `intelligence.published`; the subscriber registration is missing or runs in a different boot path. The relay marks it dispatched and it is never processed.

   **Safe fix:** Treat an event with zero handlers as a delivery failure. Retry it and eventually dead-letter it, or reject publication unless at least one handler is registered.

2. **Medium — `last_error` can persist sensitive exception content**  
   File: `modules/Platform/src/Outbox/OutboxRelay.php:141-156`

   The full exception message is stored in `outbox_events.last_error`, truncated to 990 characters. Handler/database/client exceptions can contain SQL fragments, URLs, payload values, classified identifiers, response bodies, or credentials.

   **Scenario:** A handler throws an exception containing an upstream response body or connection string. That content is persisted in a broadly readable mutable table and may later be displayed or exported.

   **Safe fix:** Store only a safe error code/class and a generated correlation ID in `last_error`; log a sanitized diagnostic separately with strict redaction. Do not persist raw exception messages.

3. **Low — Delivery result can report success even when settlement was superseded**  
   File: `modules/Platform/src/Outbox/OutboxRelay.php:106-108, 164-170`

   `settle()` does not return the guarded update count. If the lease expires and another relay claims the event, the original relay returns `dispatched` even though its settlement update affected zero rows.

   This does not overwrite the newer run’s state, but the command/job metrics can falsely report delivery by the stale run.

   **Safe fix:** Return the affected-row count from `settle()` and classify a zero-row result as `superseded`/`lost_lease`; expose it separately from `dispatched`.

## Missing tests

- An event with a registered schema but no subscribed handlers must not be marked dispatched.
- A handler exception whose message contains a fake secret, SQL, or classified-looking content must verify that `last_error` contains only sanitized information.
- A stale relay must lose settlement after lease takeover and report a non-success result.
- Two real concurrent relay processes should claim different rows and never duplicate transactional handler changes.
- A handler that succeeds, loses its lease, and is redelivered should verify receipt-based idempotency and external-effect guidance.
- Invalid/tampered payload JSON should fail safely without leaking raw contents into `last_error`.
- Migration/grant tests should verify `cais_app` has `SELECT, INSERT, UPDATE, DELETE` on `outbox_events` and only `SELECT, INSERT` on `outbox_receipts`.

## Recommendation

**No.**

The no-handler case can permanently lose an outbox event while marking it successfully dispatched, breaking the stated delivery guarantee. The branch is safe to merge after that is fixed; sanitizing `last_error` is also required before merge for the project’s sensitive-data requirements.

## What I did not check

- I did not run PHPUnit, Pest, CI, migrations, or any application command.
- I did not inspect runtime behavior under concurrent MySQL sessions.
- I did not review unrelated files outside the requested diff.
- I did not verify the implementer-reported test results independently.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

| # | Finding (short) | Severity | Decision | Fix |
|---|---|---|---|---|
| 1 | Event with no handlers is marked dispatched | High | **Accept** | `OutboxPublisher::publish()` refuses an event type with no subscribed handler. If the handlers disappear later (configuration change), the relay treats the event as a failed delivery: it retries and finally dead-letters it, never marks it dispatched. Tests for both. |
| 2 | Raw exception messages persisted in `last_error` | Medium | **Accept** | `last_error` holds only the exception class and a reference ULID. The message goes to the application log with credential shapes masked (`TypedFields::redact()`), limited to 500 characters, under the same reference. Found while testing: the `password=` pattern masked only the key, not the value; the pattern now covers the value. Test: a message with `password=hunter2` and SQL leaves neither in the table nor the secret in the log. Malformed payload JSON is now also handled as a failed delivery. |
| 3 | Stale run reports dispatched after losing its lease | Low | **Accept** | `settle()` returns whether this run still held the lease; otherwise the result is `superseded` (new counter in the relay result and `outbox:relay` output). Test simulates a takeover while the handler runs. |
| Missing tests | — | — | **Accept in part** | Added: no handlers, sanitised error, lost lease. Grants (`outbox_events` mutable, `outbox_receipts` SELECT/INSERT) are already checked table by table by `DatabaseAccountsTest` and `TableGrants::verify()` after every migration. Real multi-process concurrency tests are deferred to the Sprint 1.5 load tests; the race on receipts is covered by the simulated concurrent delivery test. |
