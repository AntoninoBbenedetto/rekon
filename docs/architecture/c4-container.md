# C4 — Containers (v1)

The deployable/runtime units that make up the system.

```mermaid
C4Container
    title Container Diagram — Reconciliation Core Slice (v1)

    Person(caller, "API Caller", "Trusted client, no auth in v1")

    Container_Boundary(app, "Reconciliation Engine (Laravel Modular Monolith)") {
        Container(api, "REST API", "Laravel (PHP 8.3)", "Handles imports, transaction queries, and manual review resolution. See ADR-001, ADR-004.")
        Container(worker, "Matching Worker", "Laravel Queue Worker (PHP 8.3)", "Consumes one matching job per imported transaction; compares it against Expected Payments if still Pending.")
    }

    ContainerDb(db, "PostgreSQL", "PostgreSQL", "Event store (source of truth) + read models (projected, disposable) + Expected Payments seed data.")
    ContainerQueue(queue, "Redis", "Redis", "Queue backend for the matching job. See failures/retry-strategy.md for redelivery handling.")

    Rel(caller, api, "CSV import, transaction queries, resolve NeedsReview", "REST/JSON")
    Rel(api, db, "Append events, read projections", "SQL")
    Rel(api, queue, "Enqueue one matching job per successfully appended TransactionImported", "Redis protocol")
    Rel(queue, worker, "Delivers matching job (at-least-once)", "Redis protocol")
    Rel(worker, db, "Replay the transaction's stream + read Expected Payments, append match/unmatch/ambiguous events", "SQL")
```

## Notes

- **One PostgreSQL database**, not one per module — a deliberate consequence
  of the Modular Monolith decision ([ADR-001](../adr/ADR-001-modular-monolith.md)):
  module boundaries are enforced in code, not by separate schemas or
  databases, at this stage.
- **Redis is queue-only** in v1 — no cache layer designed yet; adding one
  later does not change this diagram's shape, just what Redis is used for.
- The matching worker is drawn as a distinct container from the API because
  it runs as an independent queue-worker process, even though its code lives
  in the same deployable/module (`Reconciliation`) — see [c4-component.md](c4-component.md)
  for where the job class lives.
- **One job per transaction, dispatched on import** — not a periodic sweep of
  the `Pending` table. The dispatch happens only after the
  `TransactionImported` append succeeds, so a duplicate import (which collides
  and no-ops, per [ADR-006](../adr/ADR-006-deterministic-aggregate-id.md))
  enqueues nothing.
- At-least-once delivery from the queue is why the matching job must be
  idempotent against transaction state — see
  [failures/retry-strategy.md](../failures/retry-strategy.md).
