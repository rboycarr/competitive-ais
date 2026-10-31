# AI Research and Retrieval

## Scope and operating instructions

- AI outputs must be grounded in authorized source records, with direct evidence references and dates.
- Implement hybrid retrieval: MySQL filters + Meilisearch keyword + Qdrant semantic; rerank with recency, relevance and source quality.
- Chunk documents with document ID, page/section, ACL and version; embeddings inherit document access controls.
- Expose narrow typed tools (search_competitors, get_price_history, retrieve_evidence, draft_report). Never permit arbitrary production SQL or shell execution.
- Defend against prompt injection in webpages, documents, emails and tool outputs.
- Separate facts, hypotheses, confidence estimates and recommendations; allow 'insufficient evidence'.
- Log model version, prompt template version, retrieved record IDs, token/cost metrics and evaluation outcomes without leaking secrets.
- Test citation accuracy, authorization leakage, hallucination rate, conflicting sources and stale information.
- Human approval required before publication or consequential external actions.
