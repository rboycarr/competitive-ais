# TASK-0010 — Codex review of P1-05 (verbatim)

| Field | Value |
|---|---|
| Date | 2026-11-01 (21:14:54–21:15:27 UTC) |
| Codex | codex-cli 0.164.0-alpha.11.1, model gpt-5.6-luna, reasoning effort low |
| Session | 01a193d0-c451-7ee2-9c24-168cc57edbb8 |
| Mode | `codex exec -s read-only -C <repo>`, prompt = `TASK-0010.prompt.md`, branch `feature/p1-05-audit` @ `bd773f1` (clean tree) |
| Invoked by | Claude Code, **at the Product Owner's request** ("check it") |
| Raw output SHA-256 | `e6e645bf27c91946222f0d6c14c5e68af7a09b192beb2cbe228f45e2f6c107fd` |

Everything between the markers below is Codex's final message, unedited.

<!-- BEGIN CODEX OUTPUT -->
## Findings

1. **High — secrets/prompts can still be stored through permitted context values**  
   File: `modules/Platform/src/Audit/AuditActions.php:20-22`, `modules/Platform/src/Audit/AuditLogger.php:91-116`

   The allowlist blocks suspicious key names, but arbitrary free-form strings remain permitted. A module can register `reason`, `message`, or `details`, then pass an API token, password, raw prompt, or sensitive evidence text under that key. This violates the stated requirement that secrets and prompts never reach the audit table.

   Scenario: `define('auth.login_failed', ['reason'])`, followed by `record(..., ['reason' => $request->input('password')])`.

   Fix: restrict context values to typed identifiers/enums and bounded non-sensitive values; for any free text, require an explicit redaction/validation policy and reject secret-like content before hashing/insertion. Add tests using secrets under innocuous keys such as `reason`, `message`, and `value`.

2. **High — transaction enforcement only checks the default database connection**  
   File: `modules/Platform/src/Audit/AuditLogger.php:48-52`

   `AuditLogger` verifies `transactionLevel()` on `$this->db->connection()`, then writes to that same default connection. A business operation using another connection can be inside a transaction while the audit logger sees no transaction and rejects the write; conversely, code that changes connection context can write the audit row in a different transaction from the business change.

   Scenario: a model or service performs its business update inside `DB::connection('tenant')->transaction(...)`, while `AuditLogger` writes to the default connection.

   Fix: make the transaction connection explicit in the logger API or bind the logger to the caller’s transaction connection; verify the business write and audit insert use the same connection and transaction. Add a cross-connection atomicity test.

3. **Medium — row hashing omits the `audit_events.id` column despite the ERD requiring all immutable columns**  
   File: `modules/Platform/src/Audit/AuditHasher.php:31-54`

   The ERD describes `row_hash` as covering all immutable columns above it, including `id`, but `rowHash()` excludes `id`. The Merkle leaf includes the ID only after checkpointing. Therefore, an ID modification on an uncheckpointed row is not detected by `row_tampered`.

   Scenario: an attacker with direct database write access changes an uncheckpointed event’s primary key while leaving its other fields and `row_hash` unchanged; hourly verification recomputes the same row hash and reports no issue.

   Fix: either include the ID in the canonical hash using an insertion design that knows the generated ID before hashing, or explicitly redesign the canonical contract around an immutable pre-insert identifier and add an independent verification check for ID changes. Add a test that mutates an uncheckpointed event ID and expects detection.

4. **Medium — checkpointing relies on an unenforced transaction-duration assumption**  
   File: `modules/Platform/src/Audit/AuditCheckpointer.php:33-39`, `config/audit.php:12-17`

   The checkpoint selects rows by `occurred_at`, not commit time, and does not take a stable snapshot or lock. A long-running transaction can create an audit row with an old `occurred_at`, while another transaction commits a higher auto-increment ID. The checkpoint can then close a range containing the not-yet-visible lower-ID row. The verifier reports `late_commit`, but the system has already produced an incomplete checkpoint and has no implemented supplementary checkpoint until P1-50.

   Scenario: transaction A starts, creates an audit row, pauses for more than 15 minutes; transaction B commits a later audit row; checkpoint closes through B’s ID; A commits afterward.

   Fix: enforce and monitor the maximum transaction duration, or change checkpointing to use a commit-safe watermark/locking protocol that cannot close ranges containing unresolved transactions. Add a concurrency test with delayed commits and rolled-back IDs.

## Missing tests

- Free-form secrets/prompts supplied under innocuous allowlisted keys are rejected or redacted.
- Audit logging and business writes on non-default connections.
- Tampering with an uncheckpointed row’s primary ID.
- Long-running transaction committing after a checkpoint range is selected.
- Concurrent checkpoint runs with overlapping reads and inserts.
- Checkpoint behavior with auto-increment gaps caused by rollback.
- Verification while rows are inserted concurrently.
- Invalid or boundary `actor_id`, subject IDs, timestamps, and large integer context values.
- Failure of the network-row insert must roll back the audit event and business transaction.
- Schedule/job uniqueness behavior when queue locks expire or jobs overlap.

## Recommendation

**No — not safe to merge.**

The context filtering and transaction-connection issues affect core audit integrity and confidentiality. The hash/ID mismatch and checkpoint concurrency behavior also need resolution or an explicitly documented, tested design.

## What I did not check

- I did not run tests, migrations, Artisan commands, CI scripts, or application code.
- I did not inspect runtime database grants beyond the changed migration and repository diff.
- I did not review P1-50 functionality because the brief explicitly places signed manifests, external anchoring, supplementary checkpoints, and key rotation out of scope.
- I did not perform network collection, deployment, or external-service verification.
<!-- END CODEX OUTPUT -->

## Disposition (Claude Code, 2026-11-01)

| # | Finding (short) | Severity | Decision | Fix / rationale |
|---|---|---|---|---|
| 1 | Secrets or prompts can pass through free-form values under innocent keys | High | **Accept** | Context keys are now **typed** (`ulid`, `id`, `int`, `bool`, `code`, `text`, optionally lists). Only `text` is free-form: at most 500 characters, and refused if it looks like a credential (private-key header, common token prefixes, `Bearer`, `password=`-style pairs, long token-like runs). The rule for callers is documented: record IDs and codes, never user input, prompts or document content. Tests: six credential shapes under the innocent key `note` are refused; wrong types are refused. Pattern matching cannot recognise every secret, so the typed keys are the main control. |
| 2 | Transaction check only on the default connection | High | **Accept** | `AuditLogger::on($connection)` writes on the business change's connection, and the check uses that connection. Test: a transaction on another connection does not satisfy the check; `on('migrator')` inside a migrator transaction writes. All business tables use the default connection today. |
| 3 | `row_hash` omits `id` | Medium | **Reject the code change; correct the documentation** | `id` is assigned by MySQL at insert, and the app account cannot UPDATE, so it cannot be hashed into the row. Including it would not add protection: the hash is unkeyed, so anyone who can change `id` can also recompute `row_hash` for rows not yet checkpointed. `id` is bound by the Merkle leaf once checkpointed. `docs/02` §3.1 now states this. |
| 4 | Checkpoints rely on an unenforced transaction-duration limit | Medium | **Accept, deferred to P1-50** | This is the ADR-0006 design; `late_commit` is the detection. Enforcing and monitoring the duration cap, with an alert, is now part of the P1-50 acceptance criteria, next to supplementary checkpoints. Covered today: rolled-back IDs and a late commit into a closed range are tested. |
| Missing tests | — | — | **Accept in part** | Added: credential shapes, connection rule, `PHP_INT_MAX` and multilingual text round trip, type errors. Network-row insert shares the business transaction (same connection), so its failure rolls everything back. Concurrency stress tests for checkpoints are part of P1-50. |
