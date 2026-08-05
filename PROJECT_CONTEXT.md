# Financial Reconciliation Engine
## AI Project Context

This document provides architectural context for AI assistants (ChatGPT, Claude, Gemini, Cursor, GitHub Copilot) contributing to this repository.

The AI should always prioritize architectural consistency over generating code quickly.

For humans, start at [README.md](README.md). For the normative design, see
[docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
and [docs/adr/](docs/adr/) — where this document and a spec/ADR disagree, the
spec/ADR wins and this document should be corrected.

---

# Project Vision

The project is an enterprise-grade financial reconciliation engine designed for transactional environments where consistency, auditability, and idempotency are mandatory.

The goal is not simply importing payment statements.

The goal is guaranteeing financial correctness under failure.

Target environments include:

- FinTech
- Banking
- SaaS Platforms
- Public Administration
- PagoPA integrations
- PSP integrations

Fraud detection is **not** part of this system — see the core slice spec §2.

---

# Engineering Principles

Every implementation must respect these principles.

## 1. Idempotency First

Every command must be safely executable multiple times.

Running the same reconciliation twice must produce the exact same state.

Never assume commands execute only once.

The mechanism: a `Transaction`'s aggregate id is derived deterministically from
its content ([ADR-006](docs/adr/ADR-006-deterministic-aggregate-id.md)), from
the fields listed in [ADR-007](docs/adr/ADR-007-idempotency-key-composition.md).

---

## 2. Failure First

Always design assuming failures happen.

Examples:

- duplicated webhook
- network timeout
- database deadlock
- partial import
- queue retry
- duplicated bank statement
- concurrent execution

The system must recover automatically whenever possible.

Each of these has its own document in [docs/failures/](docs/failures/), stating
whether it is mitigated in v1 and by which specific mechanism.

---

## 3. Auditability

Every important action must be traceable.

Questions the system should always answer:

- Who performed the action?
- When?
- Why?
- From where?
- Previous state
- New state

Audit records must never be modified.

This is structural, not a logging convention: state is derived from an
append-only event stream, so the history cannot be overwritten. Note the
current limit on "who?" — v1 has no authentication, so the recorded actor is
self-declared ([ADR-008](docs/adr/ADR-008-no-authentication-in-v1.md)).

---

## 4. Explicit State

Avoid boolean flags.

Prefer explicit State Machines.

Illegal transitions must not be possible: each command method asserts the
current state before recording an event.

The `Transaction` state machine is defined in one place — the
[core slice spec](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md) §5.
Do not restate it elsewhere; states have already drifted once between documents.
`Settled` and `Archived` are out of scope for v1.

---

## 5. Domain Driven Design

Business rules belong inside the Domain.

Avoid placing business logic inside:

- Controllers
- Jobs
- Commands

Controllers orchestrate.

Domain models decide.

---

## 6. High Cohesion

Each module owns its business logic.

Modules should communicate using interfaces and domain events.

Avoid cross-module dependencies.

---

# Architectural Style

Current architecture:

Modular Monolith

Modules:

- SharedKernel (aggregate/event infrastructure, value objects — no business rules)
- Reconciliation (statement ingestion + matching + manual review — one
  bounded context, see ADR-001 and ADR-006)
- Settlement (future)
- Notification (future)

Auditability is a cross-cutting property of the event store, not a module of
its own.

Every module owns:

- Domain
- Application
- Infrastructure

---

# Current Technical Stack

Language

PHP 8.3+

Framework

Laravel 13

Database

PostgreSQL

Queue

Redis

Interface

REST API only — no admin panel, and specifically no Filament
([ADR-004](docs/adr/ADR-004-rest-api-only-no-admin-panel.md))

Testing

Pest

---

# Non Functional Requirements

The following are more important than feature count.

Priority order:

1. Correctness
2. Consistency
3. Idempotency
4. Auditability
5. Maintainability
6. Performance

Never sacrifice correctness for performance.

---

# Coding Guidelines

Prefer

Small classes

Immutable DTOs

Constructor Injection

Value Objects

Enums

Domain Services

Repositories only when necessary

Avoid

Fat Controllers

God Services

Static state

Hidden side effects

Duplicated business logic

---

# Transaction Strategy

Financial operations must be transactional.

Use:

- Database Transactions
- Idempotency Keys
- Unique Constraints
- Retry Policies

Concurrency is handled by optimistic conflict detection, not by holding locks
across business logic ([ADR-003](docs/adr/ADR-003-optimistic-concurrency.md)).

Avoid eventually consistent money movements.

---

# Error Handling

Expected business failures are not exceptions.

Use Result objects or Domain Exceptions only for exceptional situations.

Every failure should contain enough information for auditing.

---

# Security

Never trust external payloads.

Validate:

- Amount
- Currency
- Transaction ID
- Source
- Signature
- Hash

Sensitive information must never be logged.

v1 has no authentication or authorization at all — a deliberate, documented,
explicitly not-production-safe decision
([ADR-008](docs/adr/ADR-008-no-authentication-in-v1.md)).

---

# AI Contribution Rules

When generating code:

Always explain architectural decisions.

Prefer maintainability over clever code.

Do not introduce unnecessary dependencies.

Respect existing module boundaries.

If a change violates DDD principles, explain why.

When multiple solutions exist, present trade-offs.

---

# Repository Goals

This repository is intended to demonstrate enterprise software engineering skills.

The objective is showcasing:

- System Design
- Domain Modeling
- Failure Recovery
- Financial Consistency
- Clean Architecture
- Transactional Integrity
- Idempotent Processing
- Auditability

Code quality is preferred over feature quantity.
