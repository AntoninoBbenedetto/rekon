# Failure: Database deadlock

Status in v1: **Mitigated by design (avoided, not handled)**

## Scenario

Two concurrent processes act on overlapping data in an order that causes
PostgreSQL to detect a lock cycle and abort one transaction — classically,
process A holds a lock B wants and vice versa. In this system, plausible
sources of contention include: a re-queued matching job and a manual review
resolution touching the same `Transaction` at the same time, or two
concurrent import requests touching overlapping rows.

## Why this matters

A deadlock aborts a transaction mid-flight. If the aborted transaction had
partially applied financial state changes before rollback, or if the
application doesn't distinguish "aborted, safe to retry" from "genuinely
failed," this can produce inconsistent state or lost updates — a direct
violation of "Never sacrifice correctness for performance"
([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md), Non Functional Requirements).

## Mitigation

The design does not attempt to *handle* deadlocks gracefully — it avoids
the precondition for them. Per [ADR-003](../adr/ADR-003-optimistic-concurrency.md),
no code path holds a pessimistic lock (`SELECT ... FOR UPDATE`) across
business logic execution. Concurrent writers to the same `Transaction`
aggregate are arbitrated by the event store's unique constraint on
`(aggregate_id, version)`: the loser of a race gets a fast, well-understood
unique-constraint violation — not a lock wait that can cycle into a
deadlock.

This mirrors Principle 4 in [ARCHITECTURE_PRINCIPLES.md](../ARCHITECTURE_PRINCIPLES.md):
concurrency is handled by rejecting conflicts, not by locking.

## What is NOT covered in v1

- Deadlocks originating outside the `Transaction` aggregate's write path
  (e.g., unrelated schema migrations, ORM-generated queries touching
  multiple tables in an unexpected order) are not specifically guarded
  against. General PostgreSQL deadlock monitoring/alerting is
  infrastructure, not domain design, and out of scope here.

## Verification

Covered indirectly by the "Concurrency tests" in the [core slice spec](../superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
§10: simulate a version conflict on append and assert retry/conflict
behavior — proving the conflict path is a clean rejection, not a lock wait.
