# Session handoff for a Codex takeover — 2026-11-03

| Field | Value |
|---|---|
| Written by | Claude Code (Opus 5.5), the implementer until now |
| For | OpenAI Codex, taking over implementation at the Product Owner's request (2026-11-03) |
| Supersedes | `handoffs/SESSION-2026-11-03.md` (which remains true for everything up to pull request #25) |
| State | `main` @ `1ee8b46` (pull request #26). Three branches wait for the PO's merge, **in this order**: `feature/evidence-upload` @ `ada8ddc` (P1-32a), then `fix/registry-unique-violations` @ `74ad7e4` (P1-25a, built on P1-32a), then `docs/codex-takeover-handoff` (this file, built on P1-25a) |
| Next task number | **TASK-0069** |
| Next story | **P1-32b**: scan and store evidence (§7) |

This file is synthetic project information; it contains no secrets.

## 1. What changes for you

Until now you reviewed read-only, and `AGENTS.md` still says so: "Do not modify files unless the task brief explicitly assigns you implementation work on a named branch or worktree."

- **The assignment:** this handoff, once merged by the PO, is that assignment. You implement the stories in §7, each on its own feature branch created from an up-to-date `main`.
- **Unchanged:** every other rule in `AGENTS.md`, `CLAUDE.md` and the skills.
- **Open for the PO to confirm:** whether `AGENTS.md` should be amended to make implementer the standing role, and who reviews your branches. Until the PO decides, ask before assuming either.

**Working model, as practised so far (keep it):**
1. **Branch:** one story per branch, created from `main` after the previous merge has been verified in `origin/main`. `main` is protected: **never push to it**.
2. **Implement** the smallest coherent change: migrations, services, policies, pages, tests and docs together.
3. **Verify:** Pint, Larastan, the story's tests, the frontend checks, then the full backend suite (§4).
4. **Review:** an independent review is **mandatory** for schema, authorization, grants, evidence and file handling (`docs/12` §2). Record it in `handoffs/TASK-####-review.md`: the reviewer's output verbatim, its SHA-256, and a disposition per finding. Fix the findings, then get the fixes verified.
5. **Push** after the secrets scan passes; wait for CI to pass.
6. **Hand over:** give the PO the compare link `https://github.com/rboycarr/competitive-ais/compare/main...<branch>?expand=1` and a report in the `CLAUDE.md` §12 format. Product decisions are the PO's: ask, offer options with a recommendation, never assume.
7. **After merging:** when the PO says "merge done", verify the merge in `origin/main` (`git merge-base --is-ancestor <final commit> origin/main`) before branching again.

## 2. Phases done and pending (`docs/11` §1–§3)

| Phase | Status | Done | Pending |
|---|---|---|---|
| 0 Discovery | **Done** | Architecture, ERD, threat model, backlog, Claude ↔ Codex protocol | — |
| 1 Secure Foundation | **In progress** | Sprint 1.0 (P1-01 to P1-09); Sprint 1.1 (P1-10 to P1-17); Sprint 1.2 (P1-20 to P1-25); Sprint 1.3: P1-30, P1-30a, P1-31a, P1-31b merged; P1-32a and P1-25a done, awaiting merge | Sprint 1.3: **P1-32b**, P1-33 to P1-38. Sprint 1.4: P1-40 to P1-45, P1-48. Sprint 1.5: P1-50 to P1-54, P1-46, P1-24 (stretch), P1-47 |
| 2 Intelligence Ops | Not started | — | Prices, field reports, search, alerts, battlecards, reports, exports |
| 3 Approved Collection | Not started | — | Collectors, snapshots, dedupe, source health (the `collection` flag stays off) |
| 4 AI & RAG | Not started | — | AI gateway, hybrid retrieval, citations, evals (after the hardware spike) |
| 5 Voice & Mobility | Not started | — | PWA, push-to-talk, confirmations |
| 6 Enterprise | Not started | — | Connectors, graph, forecasting, scale |

## 3. What this session delivered

**Merged:**

| PR | Branch | Final commit | CI | Merge commit |
|---|---|---|---|---|
| #22 | `feature/pennant-feature-flags` (P1-30a) | `e3ed88e` | #118 passed | `de4f14b` |
| #23 | `feature/source-policy-review` (P1-31a) | `2fb943a` | #121 passed | `80d0af8` |
| #24 | `feature/source-lifecycle` (P1-31b) | `8d69b1d` | #124 passed | `c7be62c` |
| #25 | `chore/timestamp-story-category-flake` (P1-53, P1-54 added; test diagnostics) | `71ff22d` | #130 passed | `541811d` |
| #26 | `docs/session-handoff-merge-info` (handoff) | `ac74e4f` | #133 passed | `1ee8b46` |

**Awaiting merge:**

| Branch | Story | Final commit | CI | Reviews |
|---|---|---|---|---|
| `feature/evidence-upload` | P1-32a: upload into quarantine | `ada8ddc` | #136 passed | TASK-0064 to 0066, ending "yes" |
| `fix/registry-unique-violations` | P1-25a: a duplicate is "already taken" only when it is one | `74ad7e4` | #137 passed | TASK-0067, 0068, ending "yes" |

**P1-32a (Evidence module):**
- `evidence_assets` (`no_delete`) and `evidence_custody_events` (`append_only`); the database holds the status rules.
- **`EvidenceUploadService`:**
  - the type is detected from the bytes with `finfo` and must match the extension;
  - 50 MB cap;
  - display-only file names;
  - the key is `quarantine/<ulid>`;
  - one cleanup boundary: an object without an asset is deleted, or logged as `evidence.quarantine_orphan`.
- **Visibility (O32):** until available, only the uploader and `evidence.hold` holders see an asset.
- **The `s3-private` driver** (`Modules\Platform\Storage\PrivateBucketVisibility`): Flysystem would send `ACL: private`, which SeaweedFS refuses from the app key. **Never give the app key ACL rights.**
- **Dependency:** `league/flysystem-aws-s3-v3` 3.35.3 and `aws/aws-sdk-php` 3.401.4, pinned (ADR-0003).

**P1-25a:**
- `Modules\Platform\Database\UniqueViolation::on($e, $table, $index)` reads the index at the end of MySQL 8.4's driver message.
- Every catch of `UniqueConstraintViolationException` checks its index, and anything else is rethrown.
- Only the outbox relay may name `outbox_receipts` (code rule).

**Roadmap additions:**
- **P1-53:** convert the 43 TIMESTAMP columns; `SchemaConventionsTest` stops new ones.
- **P1-54:** performance at production-like volumes.
- **P1-52:** now also covers database-held source status transitions.

**Local operations (closed):**
- the local migrator password was changed after the TASK-0054 incident;
- the local Codex files that held the old value were deleted at the PO's request.

## 4. Environment and commands

**The stack:**
- Docker Engine in WSL2 (`Ubuntu-24.04`): PHP 8.4.27-fpm, MySQL 8.4.13, Valkey, SeaweedFS 4.51, ClamAV 1.5.4, Mailpit and Nginx (`compose.yaml`, ADR-0003).
- Start it with `docker compose up -d --wait`; first-time setup is `bash scripts/dev/setup.sh` (`docs/10` §1).
- Run commands inside WSL from the repository root.

| Purpose | Command |
|---|---|
| Format | `docker compose run --rm --no-deps -T php vendor/bin/pint` (`--test` to check only) |
| Static analysis | `docker compose run --rm --no-deps -T php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| Backend tests (migrator account, `cais_test`) | `docker compose run --rm --no-deps -T migrator php artisan test [path or --filter=…]` |
| Full backend gate, as in CI | `bash scripts/ci/backend.sh` (keep its output: write it to a file outside WSL's `/tmp`, which is cleared) |
| Frontend | `docker compose run --rm --no-deps -T node npx vitest run` and `… node npx vue-tsc --noEmit` |
| Secrets scan (before every push) | `bash scripts/ci/secrets.sh` |
| Migrations (dev database) | `docker compose run --rm migrator php artisan migrate` |
| Object-store key policy | `bash scripts/dev/verify-object-store.sh` |

**Gotchas that cost time this session:**
- **One test run at a time.** All runs share the `cais_test` database, and parallel runs corrupt each other. Don't edit PHP files while a full run is going.
- **After switching branches,** run `docker compose run --rm --no-deps -T php composer dump-autoload`. The vendor directory is a shared volume, and its autoloader keeps the last branch's classes. Static analysis then fails at bootstrap: "Module [X] has no …ServiceProvider".
- **The app account cannot read the dev database's schema** (error 1044). For read-only checks, use `DB::connection('migrator')` in `php artisan tinker` inside the migrator service.
- **CI starts only MySQL,** so tests use `Storage::fake()` for object storage. Smoke-test against the real SeaweedFS by hand: write, read back, delete, using the app's key only.
- **Laravel's fake uploads** (`UploadedFile::fake()`) report a MIME type from the *file name*. Production code must detect the type from the bytes (`EvidenceUploadService::detectedType`).
- **Windows text files:** write UTF-8 without a BOM. PowerShell's `Set-Content` and `Out-File` add a BOM or mangle non-ASCII text.
- **Pinning dependencies:** check release dates, and pin only versions at least two weeks old (`aws/aws-sdk-php` had to be pinned to an older release). Run `composer audit`, and record the versions in ADR-0003.
- **`RegistryConcurrencyTest` failed twice under load**, with "already taken" from a create in the test process. The root cause isn't proven. With P1-25a merged, the next occurrence will name the real key. If it shows up, investigate rather than retry.

## 5. Hard rules (from `AGENTS.md`, `CLAUDE.md` and the PO)

- **Credential files:** never open `.env`, `.env.*` other than `*.example`, or anything under `.secrets/`. The TASK-0054 incident came from exactly that.
- **Public repository:** synthetic data only, and no secrets. Run the secrets scan before every push.
- **No destructive commands without the PO's explicit authorization:** `migrate:fresh` on shared databases, `db:wipe`, volume removal, force pushes, history rewrites.
- **Outside the machine:** no paid services, no network collection, nothing sent to external services without approval.
- **No other machines:** make no references to any machine other than this development PC.
- **Unverified claims:** never claim a test, command or result you didn't run and see.

## 6. Conventions the reviews established (keep them)

- **Times:** DATETIME(6) in UTC, never TIMESTAMP (`SchemaConventionsTest`). A conversion's rollback must not restore TIMESTAMP.
- **Grant classes** (`config/database.php`):
  - `mutable`;
  - `no_delete`;
  - `append_only` (SELECT and INSERT);
  - `read_only`;
  - `app_excluded`.

  Every table must be classified. Governance migrations refuse to roll back while rows exist.
- **Database-held rules:** where a rule matters, the database holds it (CHECKs, composite foreign keys). Tests prove it with direct SQL and the MySQL error code:
  - 3819: CHECK failed;
  - 1452: foreign key failed;
  - 1451: the row is referenced and can't change;
  - 1142: the app account lacks the privilege;
  - 1062: duplicate key;
  - 3105: a write to a generated column.
- **Services:** every write re-checks permission (`Gate`), visibility (`AccessScope`; hidden records are 404) and state under a row lock. Audit runs in the same transaction, with codes only. Direct callers get validation errors, not type errors.
- **Duplicates:** use `UniqueViolation::on()`. Never treat every `UniqueConstraintViolationException` as "taken".
- **The migrator account:** only the Platform wiring names `migrator`. Feature flags are read through `Platform\Support\Features` (only `true` counts), and only Platform may reference Pennant.
- **Uploads are untrusted (`docs/07` T7):**
  - the type comes from the bytes;
  - file names are never keys;
  - nothing is fetched;
  - downloads will be attachments only, with the type forced (P1-34).
- **Parallel-request tests** use `tests/Support/Concurrently` (real processes, `DatabaseMigrations`), and clean up committed rows through the migrator in `tearDown`.

## 7. Next work: P1-32b, then Sprint 1.3

**P1-32b: "my upload is scanned and stored once"** (`docs/11`; ADR-0007; `docs/02` §3.5; `docs/03` Evidence). Start once P1-32a is merged.

**Acceptance:**
1. **The scan job:** a queued `ScanEvidenceJob` on the `evidence` queue.
   - Add the queue to Horizon (`config/horizon.php` supervisors run only `default` today).
   - Unique per asset; 3 tries with backoff of 30, 120 and 600 s.
2. **The ClamAV client:** a small in-house clamd `INSTREAM` client in Platform, as ADR-0003 decided (no stale package).
   - Talk to clamd over TCP on the `data` network (`clamav` service).
   - Use timeouts, and treat any error as scan status `error` (retryable), never as clean.
3. **Checks on the stored bytes:** SHA-256, and the type detected by magic bytes again. A mismatch rejects the asset.
4. **Clean bytes:**
   - copied to the `evidence` bucket (versioned, governance-mode object lock) at `sha256/<2>/<2>/<hash>`, through an `s3-private` disk;
   - `evidence_blobs`: a new migration with the specified columns, and `evidence_blob_id` on the asset;
   - created by insert-or-reload on `sha256`;
   - then: the asset becomes `available` with `scan_status` `clean`, `quarantine_key` NULL (the database requires it), and the quarantine object is deleted.
5. **Infected or mismatched files:** the asset becomes `rejected` (`infected`, or `clean` with the type refused), and the quarantine object is deleted.
6. **Records:** custody events `scanned` and `rejected` (actor `system`); audit codes only.
7. **Scan results are monotonic:** pending → clean, infected or error; error → pending to retry. A terminal result is never overwritten.
8. **Tests:**
   - EICAR (the standard, harmless test string) is rejected;
   - a type mismatch is rejected;
   - a clean file is promoted;
   - a scanner error is retried;
   - a fake clamd or a client double in CI, which has no ClamAV;
   - one manual smoke run against the real ClamAV.
9. **Review:** mandatory (evidence, file handling, schema).

**Open design points to put to the PO before or during P1-32b:**
- the default retention period (and object-lock retention) for blobs until P1-35 sets retention per asset;
- whether a file whose content type changes between upload and scan is rejected outright (recommended) or re-quarantined.

**After P1-32b:**
- P1-33: one blob, many assets, each with its own provenance.
- P1-34: authorized downloads, a pre-signed URL of at most 60 s, a custody entry.
- P1-35: legal hold.
- P1-36: evidence scopes, declared and inherited.
- P1-37: the source policy at capture; only `approved` sources; O12 (unregistered sources are allowed, labelled).
- P1-38: blob races, retries, and the quarantine sweep for `evidence.quarantine_orphan`.

## 8. Open items for the PO

- **Merge order:** P1-32a, then P1-25a, then this handoff.
- **Codex's role:** confirm the takeover in `AGENTS.md`, and name who reviews Codex's branches (§1).
- **The two P1-32b design points** in §7.
- **npm advisories:** the review of the dev-tool advisories is due 2026-11-15.
- **The intermittent `RegistryConcurrencyTest` failure:** watch for its next occurrence (§4).

## 9. Where to read more

- **Rules:** `AGENTS.md`, `CLAUDE.md`, `skills/`.
- **Design:** `docs/` (02 ERD, 03 modules, 07 threat model, 10 operations, 11 roadmap and decisions, 12 review ledger).
- **Decisions:** `docs/adr/`.
- **Records:** `handoffs/TASK-*` (briefs, prompts and verbatim reviews) and `session-history/103126.md` (the operational log, rows 129 to 158 for this session).
