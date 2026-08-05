# Architecture Principles

This document explains **why** the system is built the way it is. It does
not describe how the system works — see [architecture/overview.md](architecture/overview.md)
for that — nor does it record individual decisions with alternatives
considered — see [adr/](adr/) for that. This document is the layer above
both: the standing beliefs that make those decisions predictable rather than
ad hoc.

## 1. Consistency over latency

Financial state that is fast but wrong is worse than financial state that is
slow but correct. Every design choice in this repository — optimistic
concurrency instead of eventual consistency, synchronous validation on
import, immutable audit trail — trades some throughput for the guarantee
that the reconciled state at any point in time is the true state.

**Trade-off accepted:** the matching job and the API will occasionally
reject a write and ask a caller to retry (see [ADR-003](adr/ADR-003-optimistic-concurrency.md)).
This is treated as correct behavior, not as a defect to engineer away.

## 2. Modular Monolith, not microservices — for now

Modules (`SharedKernel`, `Reconciliation`, and later `Settlement`,
`Notification`) are separated by domain boundary and communicate through
domain events, not direct calls — but they run in one deployable, one
database, one transaction boundary.

**Why:** at this project's scale, distributed transactions and eventual
consistency across service boundaries would be pure accidental complexity —
the kind of cost microservices impose regardless of whether the domain
needs it. The module boundaries are real (see [ADR-001](adr/ADR-001-modular-monolith.md)),
so extraction later is a deployment change, not a redesign.

**Trade-off accepted:** no independent scaling or deployment per module.
Acceptable because nothing here has a scaling profile that would justify it.

## 3. The event store is the source of truth; read models are disposable

`Transaction` state is derived by folding its event stream, not stored as a
mutable row. Read model tables exist purely to make queries fast and can be
dropped and rebuilt from the event store at any time.

**Why:** this is what makes auditability (`PROJECT_CONTEXT.md` §3) a
structural property instead of a logging convention. A mutable `state`
column can be overwritten and lose history; an append-only event stream
cannot.

**Trade-off accepted:** every read path needs a projection, and replay cost
grows with stream length. Explicitly deferred rather than solved — see
"Event store snapshots" in the [core slice spec](superpowers/specs/2026-08-01-reconciliation-core-slice-design.md#11-explicitly-out-of-scope--future-work).

## 4. Concurrency is handled by rejecting conflicts, not by locking

Writes to an aggregate are conditioned on the expected version of its event
stream (optimistic concurrency), not by holding a row or table lock for the
duration of a business operation.

**Why:** pessimistic locks held across business logic are a direct path to
the deadlocks `PROJECT_CONTEXT.md` §2 lists as an expected failure mode,
especially under queue-driven concurrent processing (multiple matching job
runs, resubmitted imports). A rejected append is a known, retryable failure;
a deadlock is not.

**Trade-off accepted:** callers must implement retry-on-conflict. This
pushes complexity to the edges (API layer, job runner) rather than hiding it
inside the domain — which is the trade the project consistently makes (see
Principle 5).

## 5. Push failure handling to explicit edges, not implicit middleware

Idempotency keys, version checks, and state-machine guards are all evaluated
inside the domain/application layer, where the code that decides "is this
safe to do again?" is next to the code that does it — not in generic
retry/dedup middleware wrapping the call.

**Why:** generic failure-handling middleware can only react to the shape of
a request, not the meaning of a business operation. Only the domain knows
that re-importing the same CSV row is a no-op while re-importing a
different row with the same reference number is a conflict.

**Trade-off accepted:** more code in the domain layer than a
middleware-heavy approach would need. Accepted because it is also the code
under test in the aggregate unit tests (spec §10), which is where this
project wants its correctness guarantees to live.

## 6. Event Sourcing is a tool for one aggregate, not a house style

`Transaction` uses event sourcing because its full history *is* the audit
trail the domain requires. Expected Payments do not, because they are
reference data with no comparable audit requirement in v1.

**Why:** applying event sourcing uniformly "because it's the pattern in this
codebase" would be cargo-culting the pattern past the problem it solves.
Each aggregate's persistence strategy is a decision made on its own merits.

**Trade-off accepted:** the codebase has two persistence styles side by
side (event-sourced aggregate vs. plain Eloquent model). This inconsistency
is intentional and should not be "cleaned up" into uniformity later without
re-examining whether the audit requirement has changed.
