# Reconciliation Core Slice — Technical Design Addendum

Status: Approved
Date: 2026-08-01 (revised 2026-08-03)

## 0. Relationship to the approved spec

This document fills in the details that
[2026-08-01-reconciliation-core-slice-design.md](2026-08-01-reconciliation-core-slice-design.md)
(status: Approved) deliberately left open — quoting it directly: "exact
routes/payloads are an implementation detail for the plan, not this spec"
(§7), and no folder structure or DB schema is specified there.

This addendum does not change scope, states, events, or any decision made
in the approved spec. Where the two disagree, the approved spec wins and
this document is wrong and should be corrected. This document adds detail;
it does not re-decide anything already decided in
[ADR-001](../../adr/ADR-001-modular-monolith.md) through
[ADR-008](../../adr/ADR-008-no-authentication-in-v1.md).

It is `Approved` rather than `Draft` because
[ADR-005](../../adr/ADR-005-csv-only-ingestion-v1.md) (itself Accepted) cites
it as the authority on the CSV schema — an accepted decision cannot rest on a
draft.

## 1. Module / folder structure

```
app/
└── Modules/
    ├── SharedKernel/
    │   ├── Domain/
    │   │   ├── AggregateRoot.php
    │   │   ├── DomainEvent.php          (interface)
    │   │   ├── Actor.php                (value object: System | ApiCaller)
    │   │   ├── Money.php
    │   │   ├── Currency.php
    │   │   ├── TransactionId.php        (deriveFrom(IdempotencyKey): UUIDv5 — see ADR-006)
    │   │   └── IdempotencyKey.php
    │   ├── Application/
    │   │   └── EventStore.php           (interface: append(), loadStream())
    │   └── Infrastructure/
    │       └── PostgresEventStore.php
    │
    └── Reconciliation/
        ├── Domain/
        │   ├── Transaction.php          (aggregate root; extends SharedKernel\AggregateRoot)
        │   ├── TransactionState.php     (enum)
        │   ├── ExpectedPayment.php      (plain Eloquent model — ADR-002)
        │   └── Events/
        │       ├── TransactionImported.php
        │       ├── TransactionMatched.php
        │       ├── TransactionMarkedUnmatched.php
        │       ├── TransactionMarkedAmbiguous.php
        │       ├── TransactionReconciled.php
        │       └── TransactionRejected.php
        ├── Application/
        │   ├── ImportStatementService.php
        │   ├── ImportStatementRow.php   (DTO)
        │   ├── MatchTransactionService.php
        │   └── ResolveReviewService.php
        └── Infrastructure/
            ├── CsvStatementParser.php
            ├── MatchPendingTransactionsJob.php
            ├── TransactionReadModelProjector.php
            └── Http/
                ├── ImportsController.php
                ├── TransactionsController.php
                └── ResolveTransactionController.php
```

Notes:
- `Ingestion` and `Matching` are not separate modules: they are the names
  of two Application Services (`ImportStatementService`,
  `MatchTransactionService`/`ResolveReviewService`) inside the single
  `Reconciliation` module. Both operate on the same `Transaction` domain
  type directly — there is no cross-module dependency to reason about, and
  no dependency-direction exception needed (see
  [c4-component.md](../../architecture/c4-component.md)).
- `ImportStatementService` derives each row's `IdempotencyKey`, then calls
  `TransactionId::deriveFrom($idempotencyKey)` to get the aggregate's
  identity before creating it — see [ADR-006](../../adr/ADR-006-deterministic-aggregate-id.md).
  Deriving the key needs `occurrence_index`
  ([ADR-007](../../adr/ADR-007-idempotency-key-composition.md)), which is
  defined relative to the whole statement — so `CsvStatementParser` must group
  rows by (`reference`, `amount_minor_units`, `currency`, `statement_date`)
  and number the duplicates within each group before keys are derived. Rows
  cannot be keyed one at a time as they stream past.
- On a successful append, `ImportStatementService` dispatches one
  `MatchPendingTransactionJob` for that transaction. A row whose append
  collided (already imported) dispatches nothing.
- Controllers are thin: they translate HTTP ⇄ application service calls
  only. No business logic in controllers (`PROJECT_CONTEXT.md` §5).

## 2. Database schema

### `event_store`

The single source of truth. Append-only.

| column           | type          | notes                                           |
|-------------------|---------------|--------------------------------------------------|
| `id`              | bigint PK     | surrogate, for ordering/pagination only          |
| `aggregate_id`    | uuid          | `TransactionId`                                  |
| `version`         | integer       | 1-based, per aggregate                           |
| `event_type`      | text          | e.g. `transaction.imported`                      |
| `schema_version`  | smallint      | version of `payload`'s shape for this `event_type`; starts at `1` |
| `payload`         | jsonb         | event-specific fields (see §3)                   |
| `occurred_at`     | timestamptz   |                                                    |
| `actor_type`      | text          | `system` \| `api_caller`                          |
| `actor_id`        | text, nullable| identity when `actor_type = api_caller`           |
| `causation_id`    | uuid          | id of the command/event that caused this event    |
| `correlation_id`  | uuid          | id shared across one business process             |
| `recorded_at`     | timestamptz   | server-assigned, may differ from `occurred_at`    |

Constraints:
- `UNIQUE (aggregate_id, version)` — this is what optimistic concurrency
  control is built on ([ADR-003](../../adr/ADR-003-optimistic-concurrency.md)).
- Append is the only supported write. No `UPDATE`/`DELETE` in application
  code against this table.

On `schema_version`: v1 writes `1` everywhere and no upcaster exists — event
schema evolution is deferred ([ADR-002](../../adr/ADR-002-hand-rolled-event-sourcing.md)).
The column is present from the first migration anyway, because it is the one
piece of this design that cannot be retrofitted cheaply: adding it later means
back-filling rows whose true schema version can only be guessed from their
shape. Deferring the *mechanism* is fine; deferring the *marker* is not.

Indexes: `(aggregate_id, version)` (covered by the unique constraint, also
serves stream-load queries).

### `transactions_read_model`

Disposable projection, rebuildable by replaying `event_store`.

| column                | type        | notes                                    |
|------------------------|-------------|--------------------------------------------|
| `transaction_id`       | uuid PK     | = `aggregate_id`                           |
| `state`                 | text        | current `TransactionState`                 |
| `version`               | integer     | last applied event version, for staleness checks |
| `amount_minor_units`    | bigint      | from `Money`                               |
| `currency`              | text        | ISO 4217                                    |
| `reference`             | text        | statement reference field, used in matching |
| `statement_date`        | date        | value date from the CSV row; part of the idempotency key (ADR-007) |
| `matched_expected_payment_id` | uuid, nullable | set once matched/reconciled          |
| `imported_at`           | timestamptz |                                             |
| `updated_at`            | timestamptz | last projection write                       |

Indexes: `state` (supports `GET /transactions?state=...`), `reference`.

### `expected_payments`

Plain Eloquent table, seed/fixture data (core slice spec §2, §4).

| column       | type        | notes                     |
|---------------|-------------|----------------------------|
| `id`          | uuid PK     |                            |
| `amount_minor_units` | bigint |                            |
| `currency`    | text        |                            |
| `reference`   | text        | compared against Transaction reference |
| `created_at` / `updated_at` | timestamptz | standard Eloquent timestamps |

## 3. Event payloads

All events additionally carry the envelope fields from `DomainEvent`
(`occurredAt`, `actor`, `causationId`, `correlationId`) — only the
event-specific `payload` is shown below.

Every field below is written by the domain and read back on replay. Nothing
here is decoration: if a field is in a payload it is because some question the
audit trail must answer — *what was matched, why, by whom, on whose decision* —
cannot be answered without it.

**`TransactionImported`**
```json
{
  "transaction_id": "uuid",
  "amount_minor_units": 12345,
  "currency": "EUR",
  "reference": "string",
  "statement_date": "2026-07-31",
  "occurrence_index": 0,
  "idempotency_key": "sha256:...",
  "raw_row_checksum": "sha256:..."
}
```
`statement_date` and `occurrence_index` are the two key components not
otherwise visible on the event
([ADR-007](../../adr/ADR-007-idempotency-key-composition.md)); carrying them
makes the derivation of `idempotency_key` — and therefore of
`transaction_id` — reproducible from the event stream alone.

`raw_row_checksum` is the SHA-256 of the **raw, un-normalized CSV line** as
received, distinct from `idempotency_key` (which hashes normalized, selected
fields). It is forensic: it pins down what the source file literally said, so a
dispute can be settled against the original bytes even after normalization
rules change. It is never used for deduplication, matching, or ID derivation.

**`TransactionMatched`**
```json
{
  "transaction_id": "uuid",
  "expected_payment_id": "uuid",
  "match_type": "exact"
}
```

**`TransactionMarkedUnmatched`**
```json
{
  "transaction_id": "uuid",
  "reason": "no_candidate_found"
}
```

**`TransactionMarkedAmbiguous`**
```json
{
  "transaction_id": "uuid",
  "candidate_expected_payment_ids": ["uuid", "..."],
  "reason": "multiple_candidates" 
}
```
`reason` is `multiple_candidates` (more than one candidate shared the
reference — regardless of whether one of them matched the amount exactly) or
`partial_amount_match` (exactly one candidate, amount differs). Core slice
spec §6 defines which wins; the two are not interchangeable, because a reviewer
resolving the case needs to know whether they are choosing between competing
claims or accepting an amount discrepancy.

**`TransactionReconciled`**
```json
{
  "transaction_id": "uuid",
  "expected_payment_id": "uuid",
  "resolution": "auto" 
}
```
`resolution` is `auto` (straight from `Matched`) or `manual` (from
`NeedsReview` via resolve endpoint).

**`TransactionRejected`**
```json
{
  "transaction_id": "uuid",
  "reason": "string"
}
```
`reason` is the caller-supplied free text from the resolve endpoint (§4), and
it is **required** — a rejection is a human decision to write off a candidate
payment, and an audit trail that records the decision without the justification
answers "what happened?" but not "why?" (`PROJECT_CONTEXT.md` §3). The API must
reject a `reject` action with an empty reason (`422`) rather than persist an
event that cannot explain itself.

## 4. API contracts

No authentication in v1 ([ADR-008](../../adr/ADR-008-no-authentication-in-v1.md)).
All responses `application/json`.

### `POST /imports`

Request: `multipart/form-data`, field `file` — the CSV statement.

CSV columns (v1 custom format — [ADR-005](../../adr/ADR-005-csv-only-ingestion-v1.md)):
`reference,amount_minor_units,currency,statement_date`.

Response `200 OK`:
```json
{
  "correlation_id": "uuid",
  "rows_received": 42,
  "rows_imported": 40,
  "rows_already_imported": 2,
  "transaction_ids": ["uuid", "..."]
}
```
Import is processed row-by-row synchronously within the request, per the
approved spec's flow (§6.1), so the response describes work that has already
happened — `200`, not `202`. It is not `201` either: the request creates many
resources, not one, and there is no single `Location` to point at.

`correlation_id` is the real `correlationId` stamped on every event this
import produced, so it is a usable audit handle: it groups the whole business
process in the event store. (An earlier draft returned an `import_id` instead —
removed, because no `imports` resource exists to resolve it against. If
statement-level import tracking is ever wanted, it needs its own aggregate,
which is a design decision, not a response field.)

`rows_already_imported` counts rows whose append collided on the event store's
unique constraint — the idempotent no-op path from
[ADR-006](../../adr/ADR-006-deterministic-aggregate-id.md). Resubmitting an
identical statement is therefore a `200` with
`rows_imported: 0, rows_already_imported: 42`, which is a success, not an
error.

Errors: `422` with a per-row error list if the CSV is structurally invalid
(missing columns). A row with bad content does not fail the whole request —
see the partial-import failure handling in
[failures/network-timeout.md](../../failures/network-timeout.md) and core
slice spec §8.

### `GET /transactions?state={state}`

`state` optional, one of the `TransactionState` values. Omitted = all
states.

Response `200`:
```json
{
  "data": [
    {
      "id": "uuid",
      "state": "NeedsReview",
      "amount_minor_units": 12345,
      "currency": "EUR",
      "reference": "string",
      "statement_date": "2026-07-31",
      "imported_at": "2026-08-01T10:00:00Z"
    }
  ]
}
```

### `GET /transactions/{id}`

Response `200`:
```json
{
  "id": "uuid",
  "state": "Reconciled",
  "amount_minor_units": 12345,
  "currency": "EUR",
  "reference": "string",
  "version": 3,
  "history": [
    {
      "event_type": "transaction.imported",
      "occurred_at": "2026-08-01T10:00:00Z",
      "actor": { "type": "api_caller", "id": "..." },
      "causation_id": "uuid",
      "correlation_id": "uuid",
      "payload": { "...": "..." }
    }
  ]
}
```
`history` is the full ordered event stream — this endpoint doubles as the
audit trail view (core slice spec §7).

Errors: `404` if no aggregate exists for `{id}`.

### `POST /transactions/{id}/resolve`

Only valid when the transaction is in `NeedsReview`.

Request (choose a candidate):
```json
{ "action": "confirm", "expected_payment_id": "uuid" }
```
Request (reject):
```json
{ "action": "reject", "reason": "string" }
```

Response `200`: same shape as `GET /transactions/{id}`, reflecting the new
state.

Errors:
- `409 Conflict` — transaction is not currently `NeedsReview` (illegal
  transition, or a concurrent resolution already happened — see
  [ADR-003](../../adr/ADR-003-optimistic-concurrency.md)). Body includes the
  current state so the caller can decide whether to retry.
- `422` — `expected_payment_id` not among the candidates recorded on
  `TransactionMarkedAmbiguous`, or a `reject` action with a missing/empty
  `reason` (§3).

## 5. Open items for the implementation plan

Deliberately left for the executable implementation plan
(superpowers:writing-plans), not decided here:
- Exact PHP enum/class signatures.
- Migration file naming/ordering.
- Whether the read-model projector runs synchronously in-request or via a
  queued listener (either is consistent with this design; it's a
  performance/consistency-window choice, not a domain one).
- Validation library/approach for CSV row content (`Amount`, `Currency` per
  `PROJECT_CONTEXT.md` §"Security").
