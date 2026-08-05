# C4 — System Context (v1)

Who and what interacts with the Financial Reconciliation Engine, at the
highest level of abstraction.

```mermaid
C4Context
    title System Context — Reconciliation Core Slice (v1)

    Person(caller, "API Caller", "A trusted client submitting statements and reviewing transactions. No auth in v1 — see ADR-004.")

    System(reconciliation, "Reconciliation Engine", "Imports bank statements, matches transactions against expected payments, and reconciles them with a full audit trail.")

    Person(reviewer, "Reviewer", "A human resolving NeedsReview transactions via the API — in v1, the same trusted caller, no distinct role modeled yet.")

    Rel(caller, reconciliation, "Submits CSV statements, queries transaction state", "REST/JSON")
    Rel(reviewer, reconciliation, "Resolves ambiguous transactions", "REST/JSON")
```

## Notes

- There is exactly one external actor type in v1: an API caller, assumed
  trusted (no authentication — [ADR-004](../adr/ADR-004-rest-api-only-no-admin-panel.md)).
  "Reviewer" is shown separately to reflect a distinct *role* in the domain
  (resolving `NeedsReview` cases), even though v1 does not model it as a
  distinct system actor with its own credentials.
- No PSP, bank, or PagoPA system appears as an external actor yet — v1
  ingestion is a CSV file submitted by the caller, not a live integration
  ([ADR-005](../adr/ADR-005-csv-only-ingestion-v1.md)). A real PSP/bank
  system would appear here as a new external actor when that integration is
  designed.
- No Settlement counterpart (e.g., a payout rail) appears — out of scope for
  v1 (core slice spec §2).
