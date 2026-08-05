# ADR-004: REST API as the only interface for v1, no admin panel

Status: Accepted
Date: 2026-08-01

## Context

The stack includes Filament, a Laravel admin-panel package built on
Eloquent, largely declarative. A reconciliation system plausibly needs a
back-office UI for reviewing `NeedsReview` transactions. The question is
whether to build that UI now, and with what tool.

This repository's audience is explicitly a **generalist backend engineering**
audience (core slice spec, §1) being shown domain and API design — not a
demonstration of familiarity with a specific admin-panel package.

## Decision

For v1, the only interface is a REST API (`POST /imports`,
`GET /transactions`, `GET /transactions/{id}`,
`POST /transactions/{id}/resolve`). No Filament resources, no admin panel.

Authentication and authorization are out of scope for v1 as well — that is a
separate decision, recorded in [ADR-008](ADR-008-no-authentication-in-v1.md).

## Consequences

**Positive:**
- 100% of interface-layer effort goes into the REST API contract, which is
  the layer the project wants reviewed — request/response shape, error
  modeling, how the audit trail is exposed (`GET /transactions/{id}`
  doubling as the audit view).
- Smaller dependency footprint; nothing to configure or explain that isn't
  part of the core demonstration.
- Keeps the domain and API fully usable/testable via Pest feature tests
  without any UI layer in the way.

**Negative / accepted trade-offs:**
- No visual, no-code way to browse `NeedsReview` transactions during a demo
  or screen recording — everything is `curl`/API-client driven.
- The API is the only way to reach the system, so it inherits the full weight
  of [ADR-008](ADR-008-no-authentication-in-v1.md)'s "no authentication in v1"
  — explicitly **not production-safe**. See that ADR for the risk in full.

**Follow-up considered but deferred:** a small custom-built Livewire page
for the `NeedsReview` queue, purely for demo purposes, once the API and
domain are stable. This is pure interface-layer work that does not touch
domain design, which is why it's deferred rather than designed now.

**Revisit if:** a live demo/walkthrough need makes a visual review queue
worth the interface-layer time. (Deployment readiness is tracked separately —
see [ADR-008](ADR-008-no-authentication-in-v1.md).)
