# ADR-003: Optimistic concurrency over pessimistic locking

Status: Accepted
Date: 2026-08-01

## Context

Multiple processes can act on the same `Transaction` aggregate concurrently:
a resubmitted CSV import, a re-queued matching job (queue retry redelivery),
and a manual review resolution could in principle race. `PROJECT_CONTEXT.md`
lists "database deadlock" and "concurrent execution" as expected failure
modes that the system must handle, not avoid by assumption.

Two standard approaches exist: pessimistic locking (`SELECT ... FOR UPDATE`
or equivalent, held for the duration of the operation) or optimistic
concurrency (detect conflicting writes after the fact, reject and let the
caller retry).

## Decision

Use **optimistic concurrency control** on the `Transaction` aggregate's
event stream. Every event append is conditioned on an `expected_version`;
the `event_store` table enforces a unique constraint on
`(aggregate_id, version)`. A conflicting append fails and the caller
(matching job, API request handler) is responsible for retrying against the
new current version.

Do not hold long-lived pessimistic locks across business logic execution.

## Consequences

**Positive:**
- Directly addresses the deadlock failure mode from `PROJECT_CONTEXT.md`:
  there is no lock held across application code where a deadlock could form.
- Conflicts are cheap to detect (a unique constraint violation) and their
  resolution (retry) is uniform across every command on the aggregate.
- Matches the event-sourced model naturally — the "expected version" is
  already how the domain reasons about "what has happened so far."

**Negative / accepted trade-offs:**
- Under high contention on a single aggregate, retries could compound
  latency. Not a concern at this project's data volumes; would need
  revisiting (e.g., serializing writes per aggregate via a queue) if
  contention became real.
- Every write path must be retry-aware; a caller that ignores a conflict
  response and doesn't retry will silently drop a legitimate operation.
  Mitigated by making the conflict an explicit, typed response (not a
  generic 500), so it cannot be mistaken for an unrelated error.

**Revisit if:** profiling under realistic concurrent load shows retry storms
on hot aggregates.
