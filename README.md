# Financial Reconciliation Engine

![CI](https://github.com/AntoninoBbenedetto/rekon/actions/workflows/ci.yml/badge.svg)

*[Versione italiana](README_it.md)*

A design study in getting financial state right under failure: importing bank
statements, matching them against expected payments, and reconciling them —
where every write is idempotent, every conflict is detected rather than
locked away, and the full history of a transaction *is* the audit trail.

The domain is payment reconciliation (PagoPA/PSP-shaped). The subject is
engineering: idempotency, explicit state machines, concurrency safety, and
auditability as structural properties rather than conventions.

## Status

**Implemented.** The v1 vertical slice described below is built and tested —
CSV import, matching, manual review resolution, and the REST API are all in
place, with 95 passing tests covering unit, integration, and end-to-end paths.
The repository also contains the architecture that shaped it: specs, ADRs,
C4 diagrams, and a failure-mode analysis.

That order is deliberate. The design already had to correct itself once —
[ADR-006](docs/adr/ADR-006-deterministic-aggregate-id.md) exists because an
earlier document asserted a concurrency guarantee the mechanism, as designed,
did not actually deliver. Finding that on paper cost a document; finding it in
production would have cost duplicated money movements.

Planned stack: PHP 8.3+, Laravel 13, PostgreSQL, Redis, Pest. REST API only —
no admin panel.

## What the system does (v1 slice)

```
CSV statement → per-row idempotency key → Transaction aggregate (event-sourced)
                                                    ↓
                            matching against expected payments
                                                    ↓
                Reconciled  |  Unmatched  |  NeedsReview → Reconciled / Rejected
```

Every state transition is a domain event appended to an event store; the
queryable read model is a disposable projection of that store, never a source
of truth.

## Getting started

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Run the test suite:

```bash
docker compose exec app php artisan test
```

## Reading order

Start here if you want the *why*:

1. [docs/ARCHITECTURE_PRINCIPLES.md](docs/ARCHITECTURE_PRINCIPLES.md) — the
   standing beliefs behind the decisions (consistency over latency, conflicts
   over locks, event sourcing as a tool rather than a house style).
2. [docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
   — the normative v1 design: scope, state machine, end-to-end flow, testing
   strategy.
3. [docs/adr/](docs/adr/) — eight decisions, each with the alternatives
   considered and the trade-off accepted.
4. [docs/failures/](docs/failures/) — one document per expected failure mode,
   each stating whether v1 mitigates it and by which specific mechanism.

Reference material:

- [docs/architecture/overview.md](docs/architecture/overview.md) and the C4
  diagrams ([context](docs/architecture/c4-context.md),
  [container](docs/architecture/c4-container.md),
  [component](docs/architecture/c4-component.md)).
- [docs/superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md)
  — DB schema, event payloads, API contracts.
- [docs/api/openapi.yaml](docs/api/openapi.yaml) — the OpenAPI contract for
  the 4 REST endpoints, hand-written and versioned alongside the code.
- [docs/glossary.md](docs/glossary.md) — the vocabulary, as used *here*.
- [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) — the same context written for AI
  coding assistants.

## Decisions worth skipping to

| | |
|---|---|
| [ADR-001](docs/adr/ADR-001-modular-monolith.md) | Modular monolith, not microservices |
| [ADR-002](docs/adr/ADR-002-hand-rolled-event-sourcing.md) | Hand-rolled event sourcing — and why that would be the wrong call in production |
| [ADR-003](docs/adr/ADR-003-optimistic-concurrency.md) | Optimistic concurrency instead of pessimistic locks |
| [ADR-006](docs/adr/ADR-006-deterministic-aggregate-id.md) | Aggregate identity derived from content, killing a check-then-act race |
| [ADR-007](docs/adr/ADR-007-idempotency-key-composition.md) | What exactly gets hashed — including why two identical payments must stay two |
| [ADR-008](docs/adr/ADR-008-no-authentication-in-v1.md) | No authentication in v1, and the risk that accepts |

## Not in scope

Settlement and Notification modules, the `Settled`/`Archived` states, real
statement formats (PagoPA XML, MT940), expected payments as a managed module,
authentication, and fraud detection of any kind. Each is listed with its
reason in the core slice spec §2 and §11 — omissions here are decisions, not
oversights.

## Documentation is bilingual

Every document exists in English and Italian; the Italian version carries the
`_it` suffix (e.g. `docs/glossary_it.md`). Both are kept in sync — a change to
one is incomplete until the other matches.
