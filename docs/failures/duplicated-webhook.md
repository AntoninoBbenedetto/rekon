# Failure: Duplicated webhook delivery

Status in v1: **Not applicable yet — future scenario**

## Scenario

A future PSP/PagoPA integration delivers a webhook notification (e.g.
"payment received") and, per typical PSP guarantees, may deliver the same
webhook more than once (at-least-once delivery is the norm for these
integrations, not the exception).

## Why this is listed here now

`PROJECT_CONTEXT.md` lists "duplicated webhook" as a required failure mode
the system must handle in general. It is documented here, ahead of the
integration that would trigger it, so that the design constraint is on
record before real webhook ingestion is built — not discovered after.

**v1 has no webhook ingestion.** Statement import in v1 is a CSV file
submitted directly by a trusted caller ([ADR-005](../adr/ADR-005-csv-only-ingestion-v1.md)),
not a push notification from an external system. Do not confuse this with
[duplicated-statement.md](duplicated-statement.md), which covers the
mechanism v1 actually has.

## Intended mitigation (when webhook ingestion is designed)

The same idempotency mechanism already proven for CSV rows should extend
directly: derive a deterministic `IdempotencyKey` from the webhook payload's
identifying fields (e.g., PSP transaction ID + event type), derive the
`TransactionId` from that key (UUIDv5 — [ADR-006](../adr/ADR-006-deterministic-aggregate-id.md)),
and let a redelivered, already-processed key collide on the same aggregate
and be treated as a no-op — exactly the pattern in
[duplicated-statement.md](duplicated-statement.md). The event-sourced
`Transaction` aggregate does not need to change; only a new adapter (webhook
receiver) needs to be added inside the `Reconciliation` module's
Infrastructure layer, consistent with [ADR-005](../adr/ADR-005-csv-only-ingestion-v1.md)'s
note that new source formats are additive adapters, not redesigns.

## Verification

None yet — no code exists for this scenario. Add idempotency tests
mirroring the CSV row tests (core slice spec §10) when webhook ingestion is
designed.
