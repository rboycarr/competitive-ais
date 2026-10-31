# Security and Governance

## Scope and operating instructions

- Enforce MFA, least privilege, server-side authorization, encrypted transit/storage and segregated service accounts.
- Apply classification: public, internal, confidential, restricted; ensure search, vector retrieval, exports and AI respect permissions.
- Validate inputs, uploads and MIME types; malware-scan files; prevent SSRF, SQL injection, XSS, CSRF and insecure deserialization.
- Store credentials in a secret manager or protected environment configuration, never in source control.
- Immutable audit events for login, collection, edits, evidence reviews, exports, AI tool calls and approvals.
- Define lawful collection policy, privacy notices, consent, retention and deletion/legal-hold procedures.
- Restrict network egress for crawler/AI workers; isolate browser containers and prohibit internal-address fetches.
- Perform SAST, dependency scans, DAST, penetration tests and backup-restore exercises before production.
