# Architecture Overview

## Modules in scope (v1)

```
SharedKernel
  └── owns: AggregateRoot, DomainEvent, EventStore, Value Objects (Money,
      TransactionId — deterministically derived from IdempotencyKey, see
      ADR-006 — and IdempotencyKey itself). No business rules of its own —
      pure domain infrastructure shared by other modules.

Reconciliation
  └── owns: CSV statement parsing, per-row idempotency, the Transaction
      aggregate and its full state machine, the matching engine (queued
      job), comparison against Expected Payments, and manual review
      resolution. Publishes TransactionImported, TransactionMatched,
      TransactionMarkedUnmatched, TransactionMarkedAmbiguous,
      TransactionReconciled, TransactionRejected. Internally organized as
      two Application Services — import and matching — sharing one Domain
      layer, not two modules: they operate on the same Transaction
      aggregate and the same ubiquitous language, so they are one bounded
      context (see ADR-001).
```

`Settlement`, `Notification`, and the `Settled`/`Archived` states are out of
scope for v1 (see the [core slice spec](../superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
§2) and are not designed here.

Each module owns its own Domain / Application / Infrastructure layers (see
[ADR-001](../adr/ADR-001-modular-monolith.md)). No module reaches into
another module's persistence or internal classes directly.

## How modules communicate

Modules communicate through **domain events**, not direct method calls
across module boundaries. That rule governs how `Reconciliation` would talk
to future modules — `Settlement` reacting to `TransactionReconciled`, for
instance — via events, never by calling into `Reconciliation`'s internals.

It does not govern how the import and matching services talk to each other,
because they are not separate modules: both are Application Services inside
`Reconciliation`, and both call the `Transaction` domain type directly (its
command methods — `import()`, `match()`, `markUnmatched()`, ...). The
import service does not need to publish an event for the matching job to
"discover" a new transaction across a module boundary that does not exist:
having successfully appended `TransactionImported`, it dispatches a matching
job for that one transaction directly. `Transaction` itself — the one
aggregate in the system — is owned entirely by `Reconciliation`: one class,
one set of event definitions, not split across two modules. See the
[component diagram](c4-component.md) for exactly where.

**Matching is dispatched per transaction, not polled in batches.** One job is
enqueued per successfully imported row; the job loads that transaction, checks
it is still `Pending`, and acts. A row whose import collided on the idempotency
constraint enqueues nothing. This is the model
[failures/retry-strategy.md](../failures/retry-strategy.md) reasons about: the
queue's at-least-once delivery means a job can run twice for the same
transaction, and the aggregate's own state guard — not a scheduler — is what
makes that safe.

## Event store and read model

`Transaction` state is never read from a mutable row. Every command
(`import`, `match`, `markUnmatched`, `markAmbiguous`, `reconcile`, `reject`)
loads the aggregate by replaying its event stream from the `EventStore`,
asserts the current state allows the transition, and appends a new event.

A **read model** (denormalized table, current state + version + key fields)
is projected from every appended event, purely to make `GET /transactions`
queries fast. The read model is disposable: it can be dropped and rebuilt
by replaying the event store from scratch. The event store is the only
source of truth.

```
 CSV row → import service → [Transaction event stream] → projector → read model
                                   ↑ appended to by ↓
                          matching job / manual review
```

## Where to go next

- [c4-context.md](c4-context.md) — system boundary and external actors.
- [c4-container.md](c4-container.md) — deployable units (app, DB, queue).
- [c4-component.md](c4-component.md) — internals of each module.
- [../adr/](../adr/) — why these choices were made, with alternatives
  considered.
- [../failures/](../failures/) — how each expected failure mode is handled.
- [../glossary.md](../glossary.md) — domain terminology used throughout.
- [../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md](../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md) —
  DB schema, event payloads, API contracts.
