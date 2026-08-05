# ADR-008: No authentication or authorization in v1

Status: Accepted
Date: 2026-08-03

## Context

The v1 REST API (`POST /imports`, `GET /transactions`,
`GET /transactions/{id}`, `POST /transactions/{id}/resolve`) exposes every
operation the system has: importing financial statements, reading the full
audit trail of any transaction, and resolving a `NeedsReview` case into a
`Reconciled` or `Rejected` outcome.

This decision was originally recorded as a clause inside
[ADR-004](ADR-004-rest-api-only-no-admin-panel.md), whose subject is "REST API
as the only interface, no admin panel". That made the single decision this
project explicitly marks as **not production-safe** unfindable: nobody looks
for an authentication decision under an ADR about admin panels. It is extracted
here so that it is discoverable, and so that revisiting it does not mean
revisiting the interface decision as well.

## Decision

v1 ships **no authentication and no authorization**. The API assumes a single
trusted caller. There is no credential, no session, no API key, no role, and
no per-actor permission check.

The `Actor` value object carried on every domain event still distinguishes
`System` from an identified API caller — but in v1 the caller's identity is
**self-declared and unverified**. It is audit metadata, not an authenticated
principal.

## Consequences

**Positive:**
- Zero interface-layer effort spent on an auth scheme that would demonstrate
  nothing this project is trying to show (the audience is a generalist backend
  engineering one — see the [core slice spec](../superpowers/specs/2026-08-01-reconciliation-core-slice-design.md) §1).
- Pest feature tests exercise the domain and the API directly, with no test
  harness for tokens or sessions in the way.
- Nothing about the domain design assumes an authorization model, so adding one
  later is additive (middleware + a real `Actor` resolution) rather than a
  redesign.

**Negative / accepted trade-offs:**
- **The v1 API is not deployable anywhere reachable.** Anyone who can reach it
  can import statements, read every transaction's full history, and reconcile
  or reject transactions. This is stated as an open risk, not silently assumed
  away.
- **The audit trail's `actor` field is not trustworthy in v1.** It records who
  the caller *claimed* to be. Auditability (`PROJECT_CONTEXT.md` §3) is
  therefore structurally complete but evidentially weak until authentication
  exists — the event stream answers "who?" only as well as the caller was
  honest.
- **`Reconciled` and `Rejected` are financially meaningful, unauthenticated
  actions.** In any real deployment these are exactly the operations that need
  an authenticated, authorized, and attributable actor.
- ID unguessability offers no compensating protection: per
  [ADR-006](ADR-006-deterministic-aggregate-id.md), a `TransactionId` is
  derivable from row content and the namespace UUID by design.

**Revisit if:** the project is deployed anywhere beyond a local/demo
environment — at which point authentication is mandatory, not optional, and
the `Actor` on new events must become an authenticated principal rather than a
self-declared string. Introducing distinct roles (importer vs. reviewer), which
[c4-context.md](../architecture/c4-context.md) already models as a domain
distinction without a system boundary behind it, belongs to the same change.
