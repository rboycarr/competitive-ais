# ADR-0010 — Confidence rubric: Admiralty code, human-assigned

- **Status:** Accepted, 2026-10-31

## Context
The project prohibits fabricated confidence scores (`CLAUDE.md` §6, `skills/00`). Analysts need a consistent, explainable way to express how far a claim can be trusted.

## Decision
- Each claim carries two human-assigned ratings from the Admiralty (NATO) system:
  - **Source reliability:** A (completely reliable) … E (unreliable), F (cannot be judged).
  - **Information credibility:** 1 (confirmed by other sources) … 5 (improbable), 6 (cannot be judged).
- Sources carry a default reliability that pre-fills claims. The analyst confirms or changes it, and the reviewer sees both.
- The claim type (observed fact, reported claim, analyst inference, rumor, AI hypothesis) is recorded separately from the rating.
- AI never assigns or invents these ratings. In Phase 4 it may display them, and it may *suggest* a credibility only when that suggestion is clearly labeled and requires human confirmation.
- No numeric "confidence %" is shown anywhere unless a future ADR defines a statistically grounded method.

## Consequences
- Ratings are well known to intelligence practitioners and easy to explain to executives.
- Two dimensions are slightly more effort than one score. The UI uses a compact badge (e.g., "B2").

## Alternatives considered
- 0–100% confidence score: invites false precision and model-invented numbers.
- Single 3-level scale (low/medium/high): too coarse to separate source quality from claim corroboration.
