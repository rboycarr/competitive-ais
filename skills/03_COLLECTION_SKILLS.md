# Legal Data Collection

## Scope and operating instructions

- Prefer official APIs, RSS, licensed feeds and authorized imports; crawling only where permitted by law, terms and access restrictions.
- Never bypass login, CAPTCHA, paywalls, rate limits or technical access controls.
- Register source owner, collection method, allowed cadence, terms review, robots policy and retention requirements.
- Use Scrapy/Playwright selectively; isolate browser workers; enforce timeouts, concurrency caps, URL allowlists and SSRF protection.
- Snapshot original HTML/PDF/image with hashes and metadata; record extraction versions.
- Normalize entities, currencies, quantities, dates and regions without destroying raw observations.
- Deduplicate using canonical URLs, hashes and similarity; preserve conflicting evidence.
- Route low-confidence extraction to human review; alert on source layout changes.
- Monitor success rates, freshness, cost, blocked attempts and source health.
