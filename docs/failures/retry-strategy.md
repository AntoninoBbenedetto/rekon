# Failure: Queue retry / duplicate job execution

Status in v1: **Mitigated**

## Scenario

Redis-backed queues (and queue systems generally) offer at-least-once
delivery, not exactly-once. A matching job can be delivered and executed
more than once for the same `Transaction` — because a worker crashed after
processing but before acknowledging, because of a visibility-timeout
requeue, or because of an operator-triggered retry.

## Why this matters

If the matching job's logic assumed "I will only ever run once per
transaction," reprocessing could re-derive and re-append events for a
transaction that is already `Reconciled`, `Rejected`, or otherwise past the
point where matching applies — corrupting the state machine or producing
duplicate audit events.

## Mitigation

The matching job checks the transaction's **current state** (loaded by
replaying its event stream) before acting. If the transaction is no longer
`Pending` — already `Matched`, `Reconciled`, `Unmatched`, `NeedsReview`,
`Rejected` — the job is a no-op. This is the same "Explicit State" principle
([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §4) that prevents illegal
transitions in general: a redelivered job attempting an illegal transition
from a non-`Pending` state is rejected by the aggregate's own guards, not by
special-cased queue logic.

Combined with [optimistic concurrency](../adr/ADR-003-optimistic-concurrency.md):
even in the narrow race window where two deliveries of the same job are
*both* mid-flight against a `Pending` transaction, only one append can win;
the other gets a version conflict and, on retry, sees the transaction is no
longer `Pending` and no-ops.

`MatchPendingTransactionJob` sets `$tries = 5` with a growing backoff
(`[5, 15, 60, 180]` seconds) rather than relying on the queue worker's
default. The growing delay matters specifically because of
[ADR-003](../adr/ADR-003-optimistic-concurrency.md): the loser of an
optimistic-concurrency conflict must give the winner time to commit before
retrying, or it contends for the same aggregate row and loses again.

## What is NOT covered in v1

- **A transaction that exhausts all retries stays `Pending` forever, and
  nothing re-drives it.** `failed()` logs the transaction and correlation
  id so the failure is *visible* (in `failed_jobs` and the application log),
  but there is no scheduled sweep that finds stranded `Pending` transactions
  and requeues them. Visibility is not the same as recovery — this is the
  gap to close before this mitigation could be called complete.
- Poison-message handling (a job that fails deterministically every time)
  has no dedicated dead-letter *processing* — failures land in `failed_jobs`
  per Laravel's default and are not automatically triaged.
- No idempotency key at the *job* level (e.g., deduplicating identical
  queued job payloads before execution) — deduplication happens at the
  domain/state level instead, which is sufficient because the domain check
  is authoritative regardless of how many times the job runs.

## Verification

Covered by "Queue retry (duplicate job execution)" in the [core slice
spec](../superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
§8, and should have a corresponding Pest test: reprocessing an
already-`Reconciled` transaction through the matching job produces no new
events.
