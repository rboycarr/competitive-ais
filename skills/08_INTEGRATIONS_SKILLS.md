# Integrations and Automation

## Scope and operating instructions

- Build versioned REST endpoints and signed webhooks; use Sanctum or appropriate service-to-service auth.
- Connectors: CRM/ERP (optional), email, Teams, RSS/news feeds, approved search APIs, Power BI and n8n.
- Each connector declares scope, rate limits, data mapping, error handling, secrets, owner and disable switch.
- Inbound jobs require idempotency keys, schema validation and quarantine for malformed payloads.
- Outbound notifications use retries, delivery logs, suppression and opt-out policies.
- Never make a specific external system mandatory without an approved business requirement.
