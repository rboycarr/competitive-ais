# ADR-0008 — Local-only AI; Meilisearch hybrid search before Qdrant

- **Status:** Accepted, 2026-10-31 (Product Owner decision D3). Model and runtime choices deferred to the Phase 4 benchmark spike.

## Context
CI data is sensitive. The Product Owner chose **local models only**: no CI data is sent to third-party AI services. `CLAUDE.md` §5 also prohibits extra databases without demonstrated need. Meilisearch supports hybrid (keyword + vector) search with user-provided embeddings.

## Decision
- The AI Gateway exposes provider-agnostic interfaces (`docs/05` §2). The only enabled driver targets an **OpenAI-compatible HTTP endpoint served by a local runtime** (Ollama, llama.cpp server, or vLLM, chosen by benchmark). Embeddings and STT/TTS are also local.
- Retrieval uses MySQL + Meilisearch lexical + Meilisearch hybrid vectors. **Qdrant is added only if** the Phase 4 evaluation suites show Meilisearch hybrid search cannot meet thresholds. That would need its own ADR.
- Budgets measure local compute (tokens, seconds), and the gateway fails closed.
- Any future cloud provider requires a new ADR, a data-classification gate (e.g., public/internal only), and Product Owner approval.
- **Locality is enforced, not assumed** (amended after TASK-0001 #11). "OpenAI-compatible" describes a protocol, not a location. Endpoints must be registered in `ai_endpoints` and approved. At startup and on every call, the gateway resolves the host and requires a loopback or approved private-network address; redirects are not followed. The AI containers sit on a Docker network with no internet route. Any violation fails closed and raises an alert. Logs record the endpoint ID and network class, never prompts or evidence.

## Consequences
- No third-party data exposure from AI features.
- Quality and latency depend on local hardware (open decision O4). Phase 4 starts with a mandatory benchmark spike, and its result may prompt revisiting D3.
- One fewer datastore to run, back up, and secure in the default deployment.

## Alternatives considered
- Cloud LLM gated by classification: better quality; rejected by the Product Owner.
- Qdrant from day one: stronger vector features; unjustified before evaluation.
