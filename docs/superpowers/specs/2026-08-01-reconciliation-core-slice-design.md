# Reconciliation Core Slice — Design Spec

Status: Approved (v1 scope)
Date: 2026-08-01

## 1. Purpose and framing

This is a portfolio/demonstration project. Its goal is to showcase system design,
domain modeling, and failure-handling skills to a **generalist backend engineering**
audience — not to ship a production PSP integration. The domain (payment
reconciliation, PagoPA/PSP-style) is kept because it naturally motivates the
engineering principles the project wants to demonstrate: idempotency, explicit
state, concurrency safety, and auditability.

Code quality and depth on a narrow slice matter more than breadth of features.

## 2. Scope of v1

This spec covers a single, complete vertical slice: **import a bank statement,
match its transactions against expected payments, and reconcile them**, with
full idempotency, concurrency safety, and an immutable audit trail.

**In scope:**
- Shared Kernel (domain foundations): aggregate/event infrastructure, value objects.
- Reconciliation module: CSV statement import, matching engine, manual review resolution.
- A minimal REST API as the only interface (no admin UI).

**Out of scope for v1** (explicitly deferred, not designed here):
- Settlement module (money movement / payout / ledger).
- Notification module.
- The `Settled` and `Archived` states.
- Expected Payments as a managed module — for v1 they are seed/fixture data
  (factories / a seed command), not a CRUD-backed subsystem.
- Real-world statement formats (PagoPA XML, MT940). v1 supports a custom CSV
  format only.
- Authentication/authorization on the API — assume a trusted caller for v1;
  see [ADR-008](../../adr/ADR-008-no-authentication-in-v1.md) for the decision
  and the risk it accepts.
- **Fraud detection.** The project's original title said "Anti-Fraud"; no
  fraud-scoring, rule engine, or anomaly detection is designed here, and none
  is implied by anything in this slice. Named explicitly so the omission is a
  decision rather than an unkept promise.

## 3. Technical stack

- PHP 8.3+, Laravel 13
- PostgreSQL (event store + read models)
- Redis (queues)
- Pest (testing)
- REST API (no admin panel / no Filament — see §9)

Architecture style: Modular Monolith, DDD. Modules for v1: `SharedKernel`,
`Reconciliation`. Each owns Domain / Application / Infrastructure layers.
Import and matching are two Application Services (`ImportStatement`,
`RunMatchingForTransaction`) inside the single `Reconciliation` module, not
separate modules — they share one bounded context, one ubiquitous language,
and one aggregate (`Transaction`). Cross-module communication (e.g. with the
future `Settlement`/`Notification` modules) happens via domain events, not
direct calls; that rule governs module boundaries, not calls between
application services within the same module.

## 4. Domain foundations (Shared Kernel)

- **`AggregateRoot`** — base class managing an in-memory event stream, current
  version, and an `apply()`/`record()` pattern for producing and folding events.
- **`DomainEvent`** — interface for all events: immutable payload, `occurredAt`,
  `actor` (a value object distinguishing `System` vs an API caller identity),
  `causationId` and `correlationId` (to trace which command/event caused this
  event, and which business process it belongs to).
- **Value Objects:** `Money` (integer minor units + `Currency`, never floats),
  `TransactionId`, `IdempotencyKey` (deterministic hash of source content —
  exact composition in [ADR-007](../../adr/ADR-007-idempotency-key-composition.md)).
  `TransactionId` is not randomly generated: for the `Transaction` aggregate
  it is deterministically derived from its `IdempotencyKey` via UUIDv5 (see
  §6.1 and [ADR-006](../../adr/ADR-006-deterministic-aggregate-id.md)), so
  that aggregate identity itself carries the idempotency guarantee.
- **`EventStore`** — persistence interface plus a PostgreSQL implementation:
  an append-only table keyed by `(aggregate_id, version)` with a unique
  constraint, used for optimistic concurrency control.

**Decision — hand-rolled event sourcing, not a package** (e.g. not
`spatie/laravel-event-sourcing`): the ES infrastructure here is intentionally
small (aggregate base class, event store, replay, optimistic concurrency) and
building it is itself part of what the project demonstrates. This is a
deliberate scope trade-off specific to a portfolio project — the same
decision would likely go the other way on a real production system.

Event Sourcing is used for the `Transaction` aggregate only. Expected
Payments remain plain Eloquent models (seed data), since they are not the
subject of the state-machine/audit design being demonstrated here.

## 5. Transaction state machine

States:

```
Pending → Matched     → Reconciled
        → Unmatched
        → NeedsReview → Reconciled
                      → Rejected
```

- `Pending`: the row has landed, passed boundary validation, and is eligible
  for the matching job. This is the state a `Transaction` is born in — a row
  that fails validation never becomes a `Transaction` at all, so there is no
  separate pre-validation state. (An earlier draft listed an `Imported` state
  ahead of `Pending`; it was removed because no event ever left it — §6.1
  creates the aggregate directly in `Pending`.)
- `Matched`: exactly one exact-amount candidate found; auto-advances to
  `Reconciled`.
- `Unmatched`: no candidate found. Terminal for v1 (no retry-later mechanism
  designed yet).
- `NeedsReview`: multiple candidates, or a candidate with a non-matching
  amount (partial). Requires manual resolution via the API.
- `Reconciled`: confirmed — final state in scope for v1.
- `Rejected`: a `NeedsReview` transaction was manually determined to match
  nothing. Terminal.

Domain events: `TransactionImported`, `TransactionMatched`,
`TransactionMarkedUnmatched`, `TransactionMarkedAmbiguous`,
`TransactionReconciled`, `TransactionRejected`.

Illegal transitions (e.g. `Reconciled → Pending`) are prevented inside the
aggregate: each command method asserts the current state before recording an
event, and throws a domain exception otherwise.

## 6. End-to-end flow

1. **Import.** A CSV statement is submitted (via API). For each row, an
   `IdempotencyKey` is derived from its content —
   `reference + amount_minor_units + currency + statement_date +
   occurrence_index`, composition and rationale in
   [ADR-007](../../adr/ADR-007-idempotency-key-composition.md) — and the row's
   `TransactionId` is deterministically derived from that key (UUIDv5) — never
   randomly generated. Import always attempts to create the `Transaction` aggregate
   and append `TransactionImported` at expected version 0. Because identical
   content always derives the same `TransactionId`, re-submitting an
   already-imported row — or a genuinely concurrent duplicate racing this
   one — collides on the event store's `(aggregate_id, version)` unique
   constraint; that conflict is caught and treated as a no-op, not an error
   (see [ADR-006](../../adr/ADR-006-deterministic-aggregate-id.md)).
   Otherwise the append succeeds, the aggregate exists in `Pending`, and a
   matching job is dispatched for that one transaction. A row whose append
   collided dispatches nothing — it was already imported, and already has a
   job history of its own.
2. **Matching.** The queued job loads the one `Transaction` it was dispatched
   for and, if it is still `Pending`, gathers candidate Expected Payments by
   reference, then decides on amount:
   - 0 candidates → `TransactionMarkedUnmatched` → `Unmatched`.
   - exactly 1 candidate, amount matches exactly → `TransactionMatched` →
     auto-confirmed → `TransactionReconciled` → `Reconciled`.
   - exactly 1 candidate, amount does not match → `TransactionMarkedAmbiguous`
     (`reason: partial_amount_match`) → `NeedsReview`.
   - more than 1 candidate → `TransactionMarkedAmbiguous`
     (`reason: multiple_candidates`) → `NeedsReview`.

   **The candidate count decides before the amount does.** Several candidates
   sharing a reference is itself the ambiguity, and it stays a human decision
   even when exactly one of them happens to match the amount exactly —
   auto-reconciling that case would silently pick a winner among genuinely
   competing claims on the same money.
3. **Manual review.** For `NeedsReview` transactions, an API call resolves
   the case by picking a candidate (→ `Reconciled`) or rejecting it
   (→ `Rejected`).
4. **Projection.** Every event is projected into a read-model table
   (current state, version, denormalized fields) for fast querying by the
   API. The event store remains the single source of truth; the read model
   is disposable and rebuildable by replay.

## 7. API (interface layer)

REST API is the only interface for v1 — no admin panel. Indicative surface
(exact routes/payloads are an implementation detail for the plan, not this
spec):

- `POST /imports` — submit a CSV statement.
- `GET /transactions?state=...` — list transactions, filterable by state.
- `GET /transactions/{id}` — transaction detail, including its full event
  history (this doubles as the audit trail view).
- `POST /transactions/{id}/resolve` — resolve a `NeedsReview` transaction
  (choose a candidate or reject).

No authentication/authorization in v1 — assumed trusted caller
([ADR-008](../../adr/ADR-008-no-authentication-in-v1.md)).

## 8. Failure handling

Each failure mode from the project's engineering principles is addressed by
a specific mechanism, not by general-purpose error handling:

- **Duplicated statement/webhook:** the `Transaction`'s `TransactionId` is
  deterministically derived from its `IdempotencyKey` (UUIDv5), so
  re-submitting the same content — sequentially or concurrently — always
  targets the same aggregate. The event store's `(aggregate_id, version)`
  unique constraint arbitrates any race; the loser's conflict is treated as
  a no-op (see [ADR-006](../../adr/ADR-006-deterministic-aggregate-id.md)).
- **Partial import / network timeout:** each CSV row is processed and is
  idempotent independently; the import job as a whole is safely re-runnable.
- **Database deadlock / concurrent execution:** no long-held pessimistic
  locks. Optimistic concurrency on aggregate version — event append is
  conditioned on `expected_version`, conflicts are retried by the caller.
- **Queue retry (duplicate job execution):** the matching job checks the
  current state before acting; reprocessing an already-`Reconciled`
  transaction is a no-op.

## 9. Why not Filament (or another admin panel)

Considered and rejected for v1: Filament is largely declarative configuration
on top of Eloquent — building an admin panel with it demonstrates package
proficiency more than it demonstrates the domain/API design this project is
meant to showcase to a generalist backend audience. A REST API keeps 100% of
the effort on the layers the project is meant to prove out, and keeps the
dependency footprint smaller.

A thin custom-built review UI (e.g. a small Livewire page for the
`NeedsReview` queue) is a plausible follow-up once the API and domain are
solid, purely for demo purposes (screen recordings, live walkthroughs). It
was intentionally deferred out of this spec since it's pure interface-layer
work that doesn't touch the domain design.

## 10. Testing strategy (Pest)

- **Aggregate unit tests**, given/when/then style: given prior events, when a
  command is applied, then specific events are recorded (or a domain
  exception is thrown for illegal transitions).
- **Idempotency tests:** importing the same row twice produces exactly one
  `TransactionImported` event — and, in the other direction, a statement
  containing two rows identical on every field produces **two** aggregates,
  with resubmitting that statement still producing no third
  ([ADR-007](../../adr/ADR-007-idempotency-key-composition.md)).
- **Concurrency tests:** simulate a version conflict on append and assert the
  retry/conflict behavior.
- **Illegal transition tests:** one per invalid state transition.
- **Feature tests:** full path from CSV import through matching to a
  reconciled or rejected transaction, via the API.

## 11. Explicitly out of scope / future work

- Settlement, Notification modules; `Settled`/`Archived` states.
- Expected Payments as a real managed module (creation API, lifecycle).
- Real statement formats (PagoPA, MT940).
- Fraud detection of any kind (§2).
- API authentication/authorization ([ADR-008](../../adr/ADR-008-no-authentication-in-v1.md)).
- Event store snapshots (not needed at v1 data volumes; revisit if replay
  cost becomes an issue).
- A thin review UI (see §9).
