# ADR-005: Custom CSV format as the only ingestion format for v1

Status: Accepted
Date: 2026-08-01

## Context

Real-world bank/PSP statement formats (PagoPA XML, MT940, ISO 20022) are
verbose, spec-heavy, and largely a parsing/mapping problem once understood —
they don't exercise the parts of the system this project wants to
demonstrate (idempotency, matching, state machine, audit). Supporting them
adds significant format-parsing surface area without adding design depth.

## Decision

v1 ingestion accepts a single custom CSV format, defined by this project,
containing exactly the fields the matching engine needs (see the
[technical design addendum](../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md)
for the exact schema). Real-world formats are explicitly out of scope for
v1.

## Consequences

**Positive:**
- All ingestion design effort goes into the parts that matter for this
  project: per-row idempotency, partial-import recovery, validation at the
  system boundary — not format-specific parsing edge cases.
- The internal "statement row" shape produced by `Reconciliation`'s CSV
  parser is decoupled from any one source format, so adding a real parser
  later is additive: a new adapter producing the same internal row shape,
  not a redesign.

**Negative / accepted trade-offs:**
- The system cannot ingest a real bank statement out of the box. This is a
  portfolio project, not a deployable integration — acceptable, and stated
  explicitly rather than implied.

**Revisit if:** the project's goal shifts from demonstration to integrating
with an actual PSP/bank feed — at that point, add a format-specific parser
that adapts into the existing internal row shape, as another Infrastructure
adapter inside `Reconciliation`, not a new module.
