# ADR-006: Deterministic aggregate IDs derived from the idempotency key

Status: Accepted
Date: 2026-08-03

## Context

Importing a CSV row must be safe to repeat: the same statement resubmitted
after a timeout, a manual re-upload, or two genuinely concurrent requests
for the same content must all collapse into exactly one `Transaction`
aggregate ("Idempotency First", `PROJECT_CONTEXT.md` §1; "concurrent
execution" and "duplicated bank statement" as required failure modes, §2).

The mechanism originally implied by the design was: generate a random
`TransactionId` (UUIDv4) when importing a row, and check beforehand whether
an aggregate stream already exists for that row's `IdempotencyKey` (a
deterministic hash of its content). If it exists, treat the row as already
imported; otherwise create a new aggregate.

That check-then-act sequence has a race window: two concurrent imports of
identical content can both observe "not yet imported" before either write
lands, and both proceed — each creating its own aggregate under its own
random `TransactionId`. The two aggregates are independent as far as the
event store is concerned, so nothing rejects the second one. This directly
reproduces the "duplicated bank statement" failure mode the system is
required to prevent, specifically under concurrency.

Notably, [`failures/duplicated-statement.md`](../failures/duplicated-statement.md)
already asserted that "the event store's unique constraint on
`(aggregate_id, version)` means even a race between two concurrent imports
of the same row cannot produce two independent streams." That claim is only
true if concurrent duplicates are guaranteed to target the *same*
`aggregate_id` — which random ID generation does not guarantee. The
document described an outcome the mechanism, as designed, did not actually
deliver.

## Decision

Derive the `Transaction` aggregate's identity deterministically from its
content instead of generating it randomly:

`TransactionId::deriveFrom(IdempotencyKey $key): TransactionId`, computed
as a UUIDv5 from a fixed application namespace UUID and the idempotency
key's value. `TransactionId::generate()` (random UUIDv4) is removed —
`Transaction` is the only aggregate in v1, and every `Transaction` is
created from an `IdempotencyKey`, so a random-identity path is dead code by
construction.

Import no longer checks for existence before writing. It always derives
`TransactionId` from the row's `IdempotencyKey` and attempts
`EventStore::append($transactionId, expectedVersion: 0, [TransactionImported])`.
A conflict on that append (the `(aggregate_id, version)` unique constraint
rejecting a second `version = 1` row) means the content was already
imported — by an earlier request or a genuinely concurrent one — and is
handled as a no-op, not an error.

## Consequences

**Positive:**
- Removes the check-then-act race window entirely: correctness no longer
  depends on a read (existence check) being consistent with a
  not-yet-committed concurrent write.
- Reuses the concurrency mechanism [ADR-003](ADR-003-optimistic-concurrency.md)
  already established (unique constraint + `expected_version`) as the sole
  arbiter of the idempotency race, instead of introducing a second,
  independent deduplication mechanism.
- Makes the claim already recorded in
  [`failures/duplicated-statement.md`](../failures/duplicated-statement.md)
  actually true, rather than aspirational.
- Simplifies the import path: one unconditional write attempt, no
  existence-check query first.

**Negative / accepted trade-offs:**
- `Transaction` aggregate IDs are no longer opaque/random — a `TransactionId`
  is recoverable from CSV row content given the namespace UUID and hashing
  scheme. Not a security concern for v1 (no authorization boundary depends
  on ID unguessability, and there is no authentication at all yet — see
  [ADR-004](ADR-004-rest-api-only-no-admin-panel.md)), but a real
  consideration if the project ever needs unguessable transaction
  identifiers.
- Aggregate identity is now coupled to `IdempotencyKey`'s definition (which
  fields are hashed). Changing that definition later would change the
  derived `TransactionId` for the same real-world row — a migration
  concern for any already-imported data, not a v1 concern since there is
  none yet.
- This is a `Transaction`-specific choice, not a house style: it applies
  because "same content twice" is by definition a duplicate to collapse for
  this aggregate. A future aggregate (e.g., under `Settlement`) whose
  repeated commands are legitimately distinct actions would need its own
  identity strategy, not this one by default.

**Revisit if:** an aggregate is introduced where "same content twice" is not
a duplicate to collapse, or if ID unguessability becomes a real requirement.
