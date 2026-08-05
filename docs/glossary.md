# Glossary

Domain and technical terms as used across this repository's documentation.
Where a term has a broader meaning elsewhere, this is the meaning it has
*here*.

## Domain terms

**Transaction**
A single bank statement line item, tracked as an event-sourced aggregate
through its own state machine (`Pending → Matched/Unmatched/NeedsReview → Reconciled/Rejected`).
See [architecture/overview.md](architecture/overview.md).

**Expected Payment**
A record of a payment the system expects to receive (reference, amount),
used as the candidate pool the matching engine compares imported
Transactions against. In v1, seed/fixture data — not a managed module (core
slice spec §2).

**Reconciliation**
The overall process of confirming that an imported Transaction corresponds
to a real, expected payment, ending in the `Reconciled` state. Not to be
confused with "Matching" (a step within reconciliation) or "Settlement" (a
separate, out-of-scope module concerned with money movement).

**Matching**
The specific step of comparing a `Pending` Transaction against candidate
Expected Payments by amount and reference, producing exactly one outcome:
matched (auto-confirmed), unmatched (no candidate), or ambiguous (multiple
or partial candidates).

**Manual Review**
The human-in-the-loop step for `NeedsReview` transactions: an API caller
picks a candidate (→ `Reconciled`) or rejects the transaction (→
`Rejected`).

## Event sourcing terms

**Aggregate**
A domain object whose state is derived entirely from replaying its own
event stream, and whose command methods are the only way to produce new
events for it. `Transaction` is the only aggregate in v1.

**Aggregate Root**
The base class (`AggregateRoot` in `SharedKernel`) providing the common
event-sourcing mechanics — in-memory stream, version, `apply()`/`record()` —
that a concrete aggregate like `Transaction` extends.

**Domain Event**
An immutable fact about something that happened to an aggregate (e.g.
`TransactionImported`). Carries `occurredAt`, `actor`, `causationId`,
`correlationId` in addition to its business payload. See
[architecture/c4-component.md](architecture/c4-component.md).

**Event Store**
The append-only persistence layer for domain events, keyed by
`(aggregate_id, version)` with a uniqueness constraint used for optimistic
concurrency. The single source of truth for aggregate state — see
[ARCHITECTURE_PRINCIPLES.md](ARCHITECTURE_PRINCIPLES.md) Principle 3.

**Read Model**
A denormalized, queryable projection of the event store, built purely for
fast reads. Disposable — can be dropped and rebuilt by replaying events.
Never the source of truth.

**Replay**
Reconstructing an aggregate's current state by folding its full event
stream from the beginning. Also the mechanism for rebuilding a read model
from scratch.

**Optimistic Concurrency**
Conflict detection strategy where a write is conditioned on an expected
version rather than a held lock; a mismatch is rejected and the caller
retries. See [ADR-003](adr/ADR-003-optimistic-concurrency.md).

**Idempotency Key**
A deterministic hash of a piece of content (e.g. a CSV row), used to detect
whether that content has already been processed, making reprocessing a
safe no-op. For a statement row it hashes
`reference + amount_minor_units + currency + statement_date +
occurrence_index` — see [ADR-007](adr/ADR-007-idempotency-key-composition.md)
for why each field is in or out, and
[failures/duplicated-statement.md](failures/duplicated-statement.md) for the
failure mode it exists to prevent.

**occurrence_index**
The position of a row among the rows in the *same statement* that are
identical on every other hashed field. It is what keeps two genuinely
identical payments — same amount, same day, same reference — from collapsing
into one aggregate, since under deterministic IDs (ADR-006) identical content
would otherwise have identical identity.

**causationId / correlationId**
Two identifiers carried by every domain event. `causationId` traces which
specific command or event directly caused this event; `correlationId`
traces which broader business process (e.g. "importing statement X") this
event belongs to. Distinct from each other: causation is a direct pointer,
correlation is a shared grouping key across a whole flow.

## Value objects

**Money**
Integer minor-units amount + `Currency`. Never represented as a float,
anywhere in the domain — floating-point arithmetic on money is a correctness
bug class this project explicitly avoids.

**TransactionId**
Strongly-typed identifier for a `Transaction` aggregate, used instead of a
raw string/int to prevent mixing up identifiers across aggregate types.
Deterministically derived from the aggregate's `IdempotencyKey` (UUIDv5),
not randomly generated — see [ADR-006](adr/ADR-006-deterministic-aggregate-id.md).

## Architectural terms

**Modular Monolith**
Single deployable application internally organized into modules with
enforced boundaries (communicating via events/interfaces, not direct calls
into another module's internals), as opposed to either a single undivided
codebase or physically separate microservices. See
[ADR-001](adr/ADR-001-modular-monolith.md).

**Bounded Context** (DDD term, used loosely here)
A module's area of ownership and its own ubiquitous language — e.g.
`Reconciliation`'s notion of a "candidate" (a possible match for a
`Transaction`) doesn't leak into `Settlement`'s notion of a payout.
`Reconciliation` is one bounded context, not two: import and matching
share the same `Transaction` aggregate and the same ubiquitous language,
which is why they are Application Services inside one module rather than
separate modules — see [ADR-001](adr/ADR-001-modular-monolith.md).

**Actor** (as in `DomainEvent.actor`)
A value object distinguishing *who* caused an event: the `System` (e.g. an
automated matching job) versus an identified API caller. Part of what makes
auditability (`PROJECT_CONTEXT.md` §3) answerable from the event stream
itself.
