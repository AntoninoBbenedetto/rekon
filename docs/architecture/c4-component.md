# C4 — Components (v1)

Internals of the two in-scope modules: `SharedKernel`, `Reconciliation`.
Each follows the same internal layering
(Domain / Application / Infrastructure — [PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §5).

```mermaid
C4Component
    title Component Diagram — SharedKernel, Reconciliation (v1)

    Container_Boundary(shared, "SharedKernel") {
        Component(aggregateRoot, "AggregateRoot", "Domain", "Base class: in-memory event stream, version, apply()/record().")
        Component(domainEvent, "DomainEvent", "Domain", "Interface: payload, occurredAt, actor, causationId, correlationId.")
        Component(valueObjects, "Value Objects", "Domain", "Money, TransactionId (deriveFrom(IdempotencyKey) — see ADR-006), IdempotencyKey.")
        Component(eventStoreIface, "EventStore (interface)", "Application", "append(), loadStream() contract.")
        Component(eventStorePg, "PostgreSQL EventStore", "Infrastructure", "Append-only table, unique (aggregate_id, version).")
    }

    Container_Boundary(reconciliation, "Reconciliation") {
        Component(csvParser, "CSV Statement Parser", "Infrastructure", "Parses uploaded CSV into row DTOs.")
        Component(importService, "Import Application Service", "Application", "Derives IdempotencyKey and TransactionId per row, creates the Transaction aggregate.")
        Component(transactionAgg, "Transaction (aggregate)", "Domain", "State machine + command methods (import, match, ...). Uses AggregateRoot.")
        Component(matchingJob, "Matching Job", "Infrastructure", "Queue-consumed; one per imported transaction, loads that transaction's stream.")
        Component(matchingService, "Matching Application Service", "Application", "Compares against Expected Payments, decides match/unmatch/ambiguous.")
        Component(resolveService, "Manual Resolution Service", "Application", "Handles NeedsReview resolution API calls.")
        Component(expectedPayment, "ExpectedPayment", "Domain", "Plain Eloquent model, seed data — not event-sourced. See ADR-002.")
        Component(readModelProjector, "Read Model Projector", "Infrastructure", "Folds events into the denormalized transactions table.")
    }

    Rel(csvParser, importService, "row DTOs")
    Rel(importService, valueObjects, "TransactionId::deriveFrom(IdempotencyKey)")
    Rel(importService, transactionAgg, "import() command")
    Rel(transactionAgg, aggregateRoot, "extends")
    Rel(transactionAgg, domainEvent, "records")
    Rel(importService, eventStoreIface, "append via")
    Rel(eventStoreIface, eventStorePg, "implemented by")

    Rel(importService, matchingJob, "dispatches one per appended transaction")
    Rel(matchingJob, matchingService, "invokes")
    Rel(matchingService, expectedPayment, "reads candidates from")
    Rel(matchingService, transactionAgg, "match()/markUnmatched()/markAmbiguous() commands")
    Rel(resolveService, transactionAgg, "reconcile()/reject() commands")
    Rel(eventStorePg, readModelProjector, "events consumed by")
```

## Notes

- `Transaction`, the import service, and the matching/review services all
  live inside the single `Reconciliation` module — they are Application
  Services within one bounded context, not separate modules. Every
  relationship above stays inside one `Container_Boundary`; there is no
  cross-module dependency-direction question to answer here (contrast with
  [ADR-001](../adr/ADR-001-modular-monolith.md), which covers *actual*
  cross-module boundaries such as `Reconciliation` → `Settlement`).
- `TransactionId` is derived deterministically from `IdempotencyKey`
  (UUIDv5), not randomly generated — see
  [ADR-006](../adr/ADR-006-deterministic-aggregate-id.md). This is what
  makes a duplicate or concurrent import collapse onto the same aggregate
  instead of racing to create two.
- `ExpectedPayment` is plain Eloquent, not event-sourced — see
  [ADR-002](../adr/ADR-002-hand-rolled-event-sourcing.md) for why only
  `Transaction` uses event sourcing.
- `Read Model Projector` is infrastructure, not domain: it has no business
  rules, only a fold from event to denormalized row.
