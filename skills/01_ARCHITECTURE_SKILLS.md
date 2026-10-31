# System Architecture

## Scope and operating instructions

- Laravel owns identity, workflows, APIs, domain events and MySQL transactions.
- Vue/Inertia provides accessible responsive web and PWA interfaces.
- Redis queues with Horizon; Reverb for live alerts; Scheduler for periodic jobs.
- Python/FastAPI handles extraction, crawling and NLP via authenticated jobs.
- MySQL is source of truth; Meilisearch indexes lexical search; Qdrant stores embeddings; MinIO holds evidence blobs.
- Use outbox pattern for reliable indexing/event delivery; retry with exponential backoff and dead-letter review.
- Define modules: competitors, products, prices, sources, observations, claims, documents, alerts, reports, AI, voice, administration.
- Keep each module's migrations, models, services, policies, jobs, routes and tests together.
- Separate dev/staging/production with reproducible Docker Compose deployments; plan horizontal workers and DB replication only when metrics justify.
