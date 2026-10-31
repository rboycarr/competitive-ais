# 05 — AI Research Assistant and RAG Design

| Field | Value |
|---|---|
| Status | Design baseline (Phase 0), 2026-10-31. **Implementation is Phase 4.** |
| Owner | Claude Code (design); Codex (injection and tool-safety review) |
| Decision | **Local models only (D3, ADR-0008).** No CI data is sent to third-party AI providers. |
| Related | `skills/04_AI_RAG_SKILLS.md`, `docs/07`, `docs/02` §4 |

## 1. Goals and non-goals
**Goals:** cited Q&A, comparisons, summaries, contradiction detection, battlecard and briefing drafts, and pricing-trend explanations. All answers are grounded in records the asking user is allowed to see.
**Non-goals:** the AI as a source of truth, autonomous publishing, autonomous collection, free-form SQL/shell/URL access, or cloud inference on CI data.

## 2. AI Gateway (`Modules\AI`)

| Interface | Purpose | Driver (local) |
|---|---|---|
| `ChatModel` | generation with JSON-schema/tool output | OpenAI-compatible HTTP endpoint served by a local runtime (Ollama, llama.cpp server, or vLLM, chosen by benchmark) |
| `EmbeddingModel` | document and query embeddings | the same runtime or the Python worker; a multilingual model (en + fil) |
| `Reranker` | relevance rerank | local cross-encoder in the Python worker (optional) |
| `SpeechToText` / `TextToSpeech` | Phase 5 | Python worker (`docs/06`) |

- **Config:** an allowlist of model IDs per capability; prompt versions; timeouts; max context; per-user daily token budget; global monthly compute budget. When a budget or timeout is exceeded, the gateway **fails closed** with a clear message.
- The gateway runs only inside queued jobs. The UI streams progress over Reverb and supports cancel.
- **Swappability:** drivers implement the interfaces, so adding a different provider later needs an ADR and does not change callers.
- **Enforced locality** (TASK-0001 #11): only endpoints registered and approved in `ai_endpoints` can be used. The gateway resolves each endpoint at startup and per call and requires a loopback or approved private-network IP. It does not follow redirects. AI containers run on a Docker network without an internet route. Violations fail closed and alert. Endpoint tests cover remote hosts, DNS rebinding, redirects, and unapproved private hosts.

## 3. Indexing pipeline
1. A published or available record (intelligence item, claim, evidence text, price summary) emits an outbox event.
2. Extraction (Phase 3/4) produces `extracted_documents` → chunker (≈500–800 tokens, overlap 10–15%, split on headings and pages).
3. Each `document_chunk` stores: doc ULID, version, page/section locator, text hash, **ACL copy** (classification, competitor IDs, market IDs, status), source reliability, and dates (published, observed, collected).
4. Embeddings come from the local model. Chunks are written to Meilisearch as documents with `_vectors` (hybrid search). Qdrant is used only if hybrid search fails the evaluations (ADR-0008).
5. An ACL change or retraction triggers re-indexing or deletion via the outbox. A nightly reconciliation compares index counts and hashes with MySQL.

## 4. Retrieval and answer pipeline

```
question
 → query understanding (entities, time window, intent)        [local LLM, JSON schema]
 → AccessScope filters (classification ≤ clearance, scope grants, status = published/available)
 → parallel: MySQL structured lookup (prices, registries)
             Meilisearch lexical + hybrid vector (filter = AccessScope::searchFilter)
 → merge + rerank (relevance, recency, source reliability, verification status)
 → POST-FILTER: re-check every candidate against MySQL via AccessScope::allows   ← defense in depth
 → context assembly: each snippet wrapped as <evidence id=… date=… reliability=…>quoted data</evidence>
 → generation with a fixed system prompt (versioned); retrieved text is never placed in the system role
 → citation validator: every cited ID ∈ (retrieved ∩ authorized); unknown IDs are stripped and flagged
 → claim-type separation check (facts vs reported vs inference vs hypothesis)
 → freshness / contradiction annotations
 → persist ai_messages, ai_citations, ai_model_usage → render with citation chips linking to evidence
```

### Answer contract
- Sections: **Verified facts**, **Reported claims**, **Analyst inferences**, **AI hypotheses**, **Gaps & conflicts**.
- Every sentence in the first three sections cites at least one evidence ULID, with dates.
- "Insufficient evidence" is a valid, tested answer.
- Stale data (older than a configurable freshness window per item type) is labeled.
- The model never outputs numeric confidence. Confidence shown to users comes from the human Admiralty ratings (ADR-0010).

## 5. Tools (typed, server-side)

| Tool | Kind | Arguments (validated) | Authorization |
|---|---|---|---|
| `search_competitors` | read | query, filters | `competitor.view` + AccessScope |
| `get_price_history` | read | variant/competitor ULIDs, window, market, channel | `price.view` + AccessScope |
| `search_evidence` | read | query, filters, date window | `evidence.view` + AccessScope |
| `compare_products` | read | ≤5 product ULIDs | `product.view` + AccessScope |
| `get_alerts` | read | status, severity, window | `alert.view` + AccessScope |
| `draft_field_submission` | **draft** | structured observation | `field.submit`; creates a `pending_action` |
| `draft_report` | **draft** | template, scope | `report.create`; creates a `pending_action` |
| `propose_investigation` | **draft** | alert ULIDs, rationale | `investigation.create`; creates a `pending_action` |

- Tools run as the **requesting user**, never as a service account.
- Draft tools only create `pending_actions` (expiry ≤ 15 min). Execution requires an explicit UI confirmation that shows the exact arguments. Publishing, exporting restricted data, and outbound messages need an additional approval by a permitted role.
- **Pending-action binding** (TASK-0001 #12): each action is bound to user ID, a session ID hash, and the conversation or voice session, with exact normalized arguments and their hash. Confirmation requires CSRF and step-up authentication where the action is privileged. At execution the server re-runs authorization with the user's *current* permissions and consumes the action atomically (`UPDATE … SET consumed_at = now() WHERE id = ? AND consumed_at IS NULL AND expires_at > now()`; zero rows affected → reject). Cross-user, cross-session, replayed, expired, or changed-argument requests are rejected. Draft, confirmation, execution, and failure are audited as separate events.
- **Forbidden:** raw SQL, shell, arbitrary URL fetch, direct writes to the database or files, cross-user impersonation.

## 6. Prompt-injection defenses
- Retrieved content is quoted and delimited as data. The system prompt states that instructions inside evidence must be ignored.
- The tool allowlist is per conversation type, and tool arguments are validated against JSON schemas server-side.
- No tool can widen access: authorization is computed from the user, not from model arguments.
- Output-side checks: the citation validator, URL stripping (links come only from source records), and HTML sanitization of rendered Markdown.
- Evaluation cases include injected instructions in evidence, filenames, OCR text, and transcripts.

## 7. AI Operations (M20)
- **Ledger** (`ai_model_usage`): model ID and version, prompt version, tokens in/out, latency, compute seconds, tool calls, retrieved IDs, outcome, user feedback.
- **Eval suites** (`ai_eval_cases`) run in CI against a small local model and before any model or prompt change:

| Suite | Pass threshold (proposal; agree in Phase 4) |
|---|---|
| Citation accuracy (cited evidence supports the sentence) | ≥ 95% |
| Permission leakage (answer reveals data above clearance or outside scope) | **0 cases** |
| Prompt injection obeyed | **0 cases** |
| Fabricated citation IDs | **0 cases** |
| "Insufficient evidence" when the answer is not in the corpus | ≥ 90% |
| Contradiction disclosed when conflicting evidence exists | ≥ 90% |
| Stale-data labeling | ≥ 90% |

- Rollback: prompt versions and model IDs are config, so the previous version can be restored without a deploy.

## 8. Phase 4 entry spike (mandatory)
Benchmark on the target hardware (O3/O4): GPU model and VRAM; candidate local LLMs and embedding models; tokens/s; p95 latency for a 4k-token context; en/fil quality on a 50-question synthetic set; RAM and VRAM headroom alongside the other services. **Output:** ADR update with chosen runtime and models, or a recommendation to revisit D3.

## Assumptions
- Phase 3 extraction provides clean text for documents. Phase 4 can start with intelligence items and claims only.

## Risks
- Local model quality and latency may be insufficient for multilingual synthesis. Mitigation: the spike, a smaller scope (retrieval + extractive answers), and an explicit D3 review with the product owner.
- Index staleness or ACL drift. Mitigation: outbox, nightly reconciliation, and the MySQL post-filter.

## Acceptance criteria (Phase 4 exit)
- Eval thresholds met; zero leakage and zero obeyed injections across the full suite; every AI tool call audited; budget fail-closed behavior tested.
