# Testing, Operations and Delivery

## Scope and operating instructions

- CI pipeline: format/lint, static analysis, unit tests, integration tests, policy tests, vulnerability scans and migration checks.
- Test Laravel with Pest/PHPUnit; frontend with Vitest and Playwright; Python with pytest.
- Use Docker Compose for local/staging, pinned dependencies, automated deployments and documented rollback.
- Monitor Nginx, PHP workers, MySQL, Redis queues, crawlers, search, vector storage, API latency and AI spend.
- Set SLOs and alerts for ingestion freshness, search latency, queue lag, failed jobs and backup failures.
- Daily backups with periodic restore drills; encrypted offsite copies and documented RPO/RTO.
- Maintain runbooks for outages, compromised tokens, broken collectors, reindexing and model rollback.
