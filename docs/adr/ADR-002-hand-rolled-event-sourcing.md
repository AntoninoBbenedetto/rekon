# ADR-002: Hand-rolled Event Sourcing infrastructure over a package

Status: Accepted
Date: 2026-08-01

## Context

The `Transaction` aggregate needs event sourcing: an append-only history of
what happened to it, replayable to reconstruct current state, with
optimistic concurrency control on append. The Laravel ecosystem has mature
packages for this, most notably `spatie/laravel-event-sourcing`, which would
provide aggregate roots, projectors, reactors, and a store out of the box.

This repository's stated goal (`PROJECT_CONTEXT.md`, "Repository Goals") is
to demonstrate system design, domain modeling, and failure-handling skills.

## Decision

Build the event sourcing infrastructure by hand: a minimal `AggregateRoot`
base class (in-memory event stream, version tracking, `apply()`/`record()`),
a `DomainEvent` interface, and an `EventStore` with a PostgreSQL
implementation using an append-only table keyed by `(aggregate_id, version)`
with a unique constraint for optimistic concurrency.

Do **not** use `spatie/laravel-event-sourcing` or an equivalent package for
v1.

## Consequences

**Positive:**
- The event sourcing internals — the part of the system that most directly
  demonstrates the "auditability" and "explicit state" principles — are
  visible and reviewable in this codebase, not hidden inside a dependency.
- No coupling to a specific package's opinions about projectors, snapshotting,
  or event serialization before this project has decided what it actually
  needs.
- Full control over the exact append-conflict semantics needed for
  [ADR-003](ADR-003-optimistic-concurrency.md).

**Negative / accepted trade-offs:**
- More code to write and maintain than adopting a mature package — no
  battle-tested snapshotting, async projector pipeline, or upcasting
  strategy for event schema evolution. These are explicitly deferred (see
  the core slice spec, §11).
- This is a **portfolio-specific trade-off**. On a real production system
  handling actual financial data, the same analysis would likely favor a
  proven package: reinventing event store internals adds risk (subtle
  concurrency bugs, missing edge cases) that a mature dependency has already
  retired. This decision should not be read as a general recommendation
  against ES packages.

**Revisit if:** this project moves from portfolio/demo to a real production
deployment — re-evaluate `spatie/laravel-event-sourcing` or equivalent at
that point.
