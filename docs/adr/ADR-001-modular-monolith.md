# ADR-001: Modular Monolith over Microservices

Status: Accepted
Date: 2026-08-01

## Context

The system spans several bounded contexts with different lifecycles and
change rates: Reconciliation (statement import, candidate matching, manual
review — one bounded context, since import and matching share the same
`Transaction` aggregate and ubiquitous language), Settlement (money
movement, future), Notification (future), Audit (cross-cutting). A greenfield financial system is often assumed to need
microservices "for scale," but this project has no current requirement for
independent scaling, independent deployment, or team-boundary isolation —
there is one team and modest data volumes.

## Decision

Build a single deployable Laravel application structured as a **Modular
Monolith**. Each module (`SharedKernel`, `Reconciliation`, and later
`Settlement`, `Notification`) owns its own Domain / Application /
Infrastructure layers and communicates with other modules only through
domain events and well-defined interfaces — never by reaching into another
module's persistence or calling its internal classes directly.

## Consequences

**Positive:**
- One database, one deployment, one transaction boundary — reconciliation
  correctness (the top priority per `PROJECT_CONTEXT.md`) is easier to
  reason about without distributed transactions or eventual consistency
  between services.
- Module boundaries are enforced by convention and code organization now,
  which means they are real boundaries if extraction to services is ever
  needed later — the event-based communication style does not change.
- Lower operational overhead: no service mesh, no inter-service auth, no
  distributed tracing infrastructure needed to demonstrate the domain work
  this project is about.

**Negative / accepted trade-offs:**
- No independent scaling per module (e.g., the matching queue worker cannot
  scale independently of the rest of `Reconciliation`). Acceptable —
  nothing here has a scaling profile that requires it.
- No independent deployability. A bug in one module still requires
  redeploying the whole application. Acceptable at this stage.
- Module boundaries are enforced by discipline and code review, not by a
  network or process boundary. If violated silently, cross-module coupling
  can creep in without a compiler/deploy-time signal to catch it.

**Revisit if:** a module needs independently variable scaling, an
independent deployment cadence, or a separate team takes ownership of it.
