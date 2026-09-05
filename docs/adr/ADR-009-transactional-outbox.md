# ADR-009: Transactional outbox for cross-system writes

Status: Accepted
Date: 2026-09-05

## Context

`ImportStatementService::import()` performs three writes across two
different systems for every imported row:

1. `TransactionRepository::save($transaction)` → `event_store` (Postgres,
   atomic on its own via the `DB::transaction` wrapping inserts in
   `PostgresEventStore::append()`).
2. `TransactionReadModelProjector::project($transaction)` → `transactions_read_model`
   (Postgres, a separate query, outside any transaction).
3. `MatchPendingTransactionJob::dispatch(...)` → Redis queue.

Only the first write is transactional. The process can die between any two
of these steps — deploy, OOM kill, `SIGKILL`, worker crash — leaving the
system in a state nothing repairs:

- **Crash after 1:** the `TransactionImported` event exists in the event
  store, but the read model has no row for it. `GET /transactions/{id}`
  returns 404 for a transaction that genuinely exists. Nothing fixes this,
  because no projection-rebuild command exists — which also means the
  README's claim that "the queryable read model is a disposable projection
  of that store, never a source of truth" is not yet true of the code: a
  projection is only disposable if you can actually rebuild it.
- **Crash after 2:** the transaction is `Pending` in both the event store
  and the read model, but the matching job never reached Redis. Nothing
  will ever match it. It stays `Pending` forever, silently — an
  unreconciled payment, which is exactly the failure mode this project
  exists to prevent (`PROJECT_CONTEXT.md` §2, "Financial Consistency").

Two straightforward fixes do not close this:

- Wrapping steps 1–2 in one `DB::transaction` closes the first window
  (both writes are Postgres, so they commit together), but does nothing for
  the second: Redis does not participate in a Postgres transaction. If the
  commit succeeds and the dispatch fails, the transaction is still stuck
  `Pending`. If the dispatch succeeds but the commit fails, it is worse —
  the worker runs against an aggregate that was never persisted.
- `DB::afterCommit()` moves the dispatch after the commit, eliminating
  "job without data," but not "data without job": the process can still die
  between the commit and the hook's execution. This shrinks the window, it
  does not close it — and in a system whose whole premise is guaranteed
  idempotent reconciliation, that gap matters.

The general problem: two writes to two different systems cannot be made
atomic without a distributed commit, and a distributed commit (XA,
two-phase commit) is a worse cure than the disease for a monolith with one
Redis queue.

`MatchPendingTransactionJob::handle()` already re-checks the transaction's
state before acting and is a no-op (beyond re-projecting) if it is no
longer `Pending`. This existing idempotency is the precondition that makes
an at-least-once delivery mechanism safe to introduce.

## Decision

Adopt the **transactional outbox** pattern: since two systems cannot be
written atomically, write only to Postgres — including the *intent* to
write to Redis — inside the same transaction as the domain writes, and let
a separate, decoupled process turn that intent into the actual dispatch.

Concretely:

- A new `outbox` table (`message_type`, `payload` jsonb, `correlation_id`,
  `created_at`) lives entirely inside the `Reconciliation` module
  (`app/Modules/Reconciliation/Infrastructure/Outbox/OutboxWriter.php`),
  not in `SharedKernel`: `Reconciliation` is the only consumer today, and
  the relay that reads this table necessarily knows about
  `MatchPendingTransactionJob`, which is business-specific — `SharedKernel`
  must not depend on it.
- `ImportStatementService::import()` wraps `repository->save()`,
  `projector->project()`, and `outbox->publish(...)` in a single
  `DB::transaction()`. All three rows exist, or none do — there is no
  longer an intermediate state the system cannot recover from on its own.
  The same wrapping is applied to `MatchPendingTransactionJob::handle()`'s
  `save()` + `project()` pair, for the same reason (event and projection
  must commit together), even though that path does not need an outbox row
  since it dispatches nothing further downstream.
- A relay artisan command, `reconciliation:relay-outbox`, reads outbox rows
  (`SELECT ... FOR UPDATE SKIP LOCKED` to allow multiple relays running
  concurrently), dispatches the real job, and deletes the row. If the relay
  dies between dispatch and delete, the row survives and is republished on
  the next run. This is **at-least-once** delivery — the only guarantee
  achievable without a distributed commit — and it is safe precisely
  because `MatchPendingTransactionJob` is already idempotent.
- `reconciliation:rebuild-projection` truncates `transactions_read_model`
  and replays it from `event_store`, making the "disposable projection"
  claim in the README actually true.

**Why a separate `outbox` table and not the `event_store` itself as an
ordered log with a `last_event_id` checkpoint:** `event_store.id` is a
Postgres bigserial, and in Postgres a sequence value is assigned *before*
commit. Two concurrent transactions can take ids 100 and 101; if 101
commits first, a relay reading at that moment sees 101, advances its
checkpoint, and never sees 100 when it commits a moment later — a silently
lost event, under load, exactly when it hurts most. This can be worked
around (only read events older than N seconds, track `pg_snapshot_xmin`,
logical decoding), but all of those are extra machinery. A separate table
with delete-after-publish has no ordering to preserve: read whatever rows
are visible, publish them, delete them. A row invisible this pass is simply
visible next pass.

## Consequences

**Positive:**
- No more intermediate state the system cannot recover from by itself:
  event store, read model, and outbox intent commit together or not at all.
- The read model becomes an actually rebuildable projection, matching the
  README's existing claim instead of contradicting it.
- No new infrastructure: no Kafka, no Debezium, no CDC — a table, a relay
  command, and a rebuild command, reusing the idempotent consumer that
  already existed.
- The outbox row format (`message_type`/`payload`/`correlation_id`) is
  reusable if a second message type or module needs it later, without being
  built as a generic cross-module component before there is a second
  consumer to justify it.

**Negative / accepted trade-offs:**
- At-least-once delivery means `MatchPendingTransactionJob` can run more
  than once for the same transaction. Already safe today because the job
  re-checks state before acting, but any future outbox consumer must keep
  that same idempotency invariant.
- The relay is one more long-running process to keep alive and monitor
  (scheduler or loop); if it stops, the outbox grows silently unless
  something watches its age — mitigated with a log warning when the oldest
  unprocessed row exceeds a threshold, not a new metrics stack.
- There is a small added latency between "row committed" and "job actually
  in the Redis queue," compared to the previous (unsafe) synchronous
  dispatch.

**Revisit if:** a second module needs outbox-style delivery (promote the
table and writer to `SharedKernel`, keeping module-specific dispatch logic
out of it), or message volume grows to the point where a CDC-based consumer
becomes justified.
