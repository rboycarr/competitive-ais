# ADR-0001 — Laravel modular monolith with enforced module boundaries

- **Status:** Accepted, 2026-10-31
- **Deciders:** Product Owner (approved plan); Claude Code (author)

## Context
The catalog has 20 functional modules. `CLAUDE.md` §5 and `skills/01` call for a modular monolith with bounded contexts, and they prohibit microservices without a demonstrated need. Without enforcement, a monolith tends to become a tangle of cross-module model access.

## Decision
- One Laravel application. Each bounded context lives in `modules/<Module>/` with its own `src`, `database`, `routes`, `resources/js/Pages`, `tests`, and a ServiceProvider (layout in `docs/01` §3).
- Composer PSR-4 maps `Modules\<Module>\` to `modules/<Module>/src`. Providers are discovered automatically.
- Modules communicate through `Contracts` (interfaces and read DTOs) and domain `Events` only. `Modules\Platform` is the shared kernel.
- Pest `arch()` tests in CI fail any import of another module's internals.
- Python workers (Phase 3+) are the only out-of-process components, behind versioned, signed contracts (`docs/08` §3).

## Consequences
- Clear ownership and testable boundaries, with a single deploy unit and a single database.
- Slight overhead (contracts, DTOs) compared with direct model access.
- A module can be extracted later if metrics justify it, because its dependencies are explicit.

## Alternatives considered
- Default Laravel `app/` layout: simpler at first, but boundaries are not enforceable.
- Third-party module packages (e.g., nwidart/laravel-modules): extra dependency and conventions; plain PSR-4 plus a small loader is enough.
- Microservices: rejected per `CLAUDE.md` §5.
