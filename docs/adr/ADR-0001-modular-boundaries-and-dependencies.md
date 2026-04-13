# ADR-0001: Modular Boundaries and Dependency Rules

## Status

Accepted

## Date

2026-04-12

## Context

The 45s project needs to support:
- long-term maintainability
- multiplayer game runtime correctness
- algorithmic AI now and LLM-backed AI later
- a PWA frontend that can evolve independently
- deployment on shared hosting (PHP + MySQL)

Without hard boundaries, gameplay rules, AI strategy, API routing, and UI logic will drift into each other and become expensive to change.

## Decision

Adopt four bounded modules with strict one-way dependencies.

### Module A: Platform and Ecosystem Operations

Responsibilities:
- authentication, user accounts, sessions
- lobby lifecycle and game discovery
- configuration, deployment concerns, observability
- admin operations and product-level policies

### Module B: Match Runtime (Single Game Backend)

Responsibilities:
- authoritative state machine for one game
- validation of legal actions and turn order
- phase transitions, trick and hand progression
- event log and persistence coordination

### Module C: Decision Engine (AI and Algorithms)

Responsibilities:
- choose AI actions (bid, trump, discard, play)
- provide optional reasoning for debug mode
- support multiple providers via interfaces

Initial provider:
- algorithmic heuristics only

Future providers:
- LLM-backed provider(s) behind the same interfaces

### Module D: Client Experience (Frontend PWA)

Responsibilities:
- render game state and collect user actions
- reconnect and offline shell UX
- accessibility, performance, installability

## Dependency Rules

Allowed:
- A -> B
- A -> C
- A -> D
- B -> C (interface only)
- D -> A/B public APIs

Forbidden:
- C -> B direct state mutation
- D -> C direct calls
- B -> D dependencies

## Module Contracts

All module interactions must use explicit DTO-style contracts:
- ActionCommand
- ActionResult
- GameStateView
- AIRequest
- AIResponse

Runtime validation in Module B is always authoritative, including for AI actions.

## Consequences

Positive:
- AI can evolve from heuristics to LLM without rewriting gameplay core.
- Frontend technology can change with stable API contracts.
- Rules logic remains testable and deterministic.
- Team ownership can be split cleanly.

Tradeoffs:
- More upfront discipline and interface design work.
- Extra mapping code between internal models and API DTOs.

## Guardrails

- No rules logic in controllers/routes.
- No direct DB writes from AI providers.
- Every action path ends with runtime legality validation.
- New cross-module dependency requires ADR update.

## Implementation Notes

- Place core rules and state transitions in domain services.
- Keep adapters for HTTP, DB, and external AI providers in infrastructure.
- Add contract tests for B<->D payload stability.
- Add simulation tests to verify deterministic outcomes with seeded randomness.
