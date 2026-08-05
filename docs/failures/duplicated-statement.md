# Failure: Duplicated bank statement

Status in v1: **Mitigated**

## Scenario

The same CSV statement (or a statement containing rows already imported in
a previous file) is submitted again — because a caller retried after a
timeout without knowing the first request succeeded, because of a manual
re-upload, or because an upstream system resends the same file.

## Why this matters

Without protection, re-importing a statement would create duplicate
`Transaction` aggregates for the same underlying bank movement, which would
then be matched and reconciled twice — double-counting real money movements.
This is exactly the class of bug "Idempotency First"
([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §1) exists to prevent.

## Mitigation

Each CSV row's `IdempotencyKey` is a deterministic hash of
`reference + amount_minor_units + currency + statement_date + occurrence_index`
— the exact composition, and why each field is in or out, is
[ADR-007](../adr/ADR-007-idempotency-key-composition.md). Notably the file's own
identity is *not* part of it, which is what makes a re-upload under a different
filename derive the same key rather than a new one. The row's `TransactionId`
is itself deterministically derived from that `IdempotencyKey` (UUIDv5) rather
than randomly generated — see
[ADR-006](../adr/ADR-006-deterministic-aggregate-id.md).

There is no separate "check if this was already imported" step. The import
service always attempts to create the aggregate and append
`TransactionImported` at expected version 0. Because identical content
always derives the same `TransactionId`, this is enforced directly at the
storage layer: the event store's unique constraint on
`(aggregate_id, version)` rejects a second `version = 1` for that same
aggregate. Re-submitting an already-imported row — or a genuinely
concurrent duplicate racing this one — hits that conflict and is treated
as a no-op, not an error. The loser of any race gets a conflict, never a
duplicate (see [ADR-003](../adr/ADR-003-optimistic-concurrency.md)), because
the race is always over the *same* `aggregate_id`, not two different
randomly-generated ones.

## What is NOT covered in v1

- Two rows that represent the same real-world movement but with
  content that hashes differently (e.g., a statement re-exported with a
  different timestamp format) are not detected as duplicates — the
  `IdempotencyKey` is content-based, not semantic. This is a known
  limitation, not a bug: solving it requires a fuzzy-matching design that is
  out of scope for v1.
- **Partial or reordered re-exports.** Deduplication is per-statement, because
  `occurrence_index` (ADR-007) is defined relative to the file a row arrives
  in. Re-submitting the *same* file is always a no-op; re-submitting a file
  that contains a reordered subset of rows already imported can assign a
  different index to the same real-world payment and import it again. The
  guarantee here is "the same statement is safe to resubmit", not "any file
  overlapping previously imported data is safe to resubmit".

## The opposite failure: two payments that are genuinely identical

A statement can legitimately contain two rows that are identical in every
field — two real payments of the same amount, same day, same reference. The
dangerous mistake would be to collapse them: that under-counts real money, and
under deterministic aggregate IDs it is not repairable afterwards, because the
second payment has no distinct identity to be given.

This is why `occurrence_index` is part of the key
([ADR-007](../adr/ADR-007-idempotency-key-composition.md)): the first such row
gets index `0`, the second index `1`, so they derive different keys, different
`TransactionId`s, and two independent aggregates — while a resubmission of the
whole statement still reproduces both indices exactly and remains a no-op.

## Verification

Covered by the "Idempotency tests" in the [core slice spec](../superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
§10: importing the same row twice must produce exactly one
`TransactionImported` event. That suite must also cover the opposite
direction — a statement containing two identical rows produces **two**
aggregates, and resubmitting that same statement still produces no third one.
