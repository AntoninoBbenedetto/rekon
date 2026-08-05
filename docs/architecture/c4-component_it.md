# C4 — Componenti (v1)

Interni dei due moduli in scope: `SharedKernel`, `Reconciliation`.
Ognuno segue lo stesso layering interno
(Domain / Application / Infrastructure — [PROJECT_CONTEXT_it.md](../../PROJECT_CONTEXT_it.md) §5).

```mermaid
C4Component
    title Diagramma dei Componenti — SharedKernel, Reconciliation (v1)

    Container_Boundary(shared, "SharedKernel") {
        Component(aggregateRoot, "AggregateRoot", "Domain", "Classe base: stream di eventi in memoria, versione, apply()/record().")
        Component(domainEvent, "DomainEvent", "Domain", "Interfaccia: payload, occurredAt, actor, causationId, correlationId.")
        Component(valueObjects, "Value Object", "Domain", "Money, TransactionId (deriveFrom(IdempotencyKey) — vedi ADR-006), IdempotencyKey.")
        Component(eventStoreIface, "EventStore (interfaccia)", "Application", "Contratto append(), loadStream().")
        Component(eventStorePg, "PostgreSQL EventStore", "Infrastructure", "Tabella append-only, unique (aggregate_id, version).")
    }

    Container_Boundary(reconciliation, "Reconciliation") {
        Component(csvParser, "CSV Statement Parser", "Infrastructure", "Fa il parsing del CSV caricato in DTO di riga.")
        Component(importService, "Import Application Service", "Application", "Deriva IdempotencyKey e TransactionId per riga, crea l'aggregate Transaction.")
        Component(transactionAgg, "Transaction (aggregate)", "Domain", "Macchina a stati + metodi comando (import, match, ...). Usa AggregateRoot.")
        Component(matchingJob, "Matching Job", "Infrastructure", "Consumato dalla coda; uno per transazione importata, carica lo stream di quella transazione.")
        Component(matchingService, "Matching Application Service", "Application", "Confronta con gli Expected Payment, decide match/unmatch/ambiguous.")
        Component(resolveService, "Manual Resolution Service", "Application", "Gestisce le chiamate API di risoluzione NeedsReview.")
        Component(expectedPayment, "ExpectedPayment", "Domain", "Modello Eloquent semplice, dati seed — non event-sourced. Vedi ADR-002.")
        Component(readModelProjector, "Read Model Projector", "Infrastructure", "Ripiega gli eventi nella tabella transactions denormalizzata.")
    }

    Rel(csvParser, importService, "DTO di riga")
    Rel(importService, valueObjects, "TransactionId::deriveFrom(IdempotencyKey)")
    Rel(importService, transactionAgg, "comando import()")
    Rel(transactionAgg, aggregateRoot, "estende")
    Rel(transactionAgg, domainEvent, "registra")
    Rel(importService, eventStoreIface, "append tramite")
    Rel(eventStoreIface, eventStorePg, "implementato da")

    Rel(importService, matchingJob, "ne dispatcha uno per transazione appesa")
    Rel(matchingJob, matchingService, "invoca")
    Rel(matchingService, expectedPayment, "legge i candidati da")
    Rel(matchingService, transactionAgg, "comandi match()/markUnmatched()/markAmbiguous()")
    Rel(resolveService, transactionAgg, "comandi reconcile()/reject()")
    Rel(eventStorePg, readModelProjector, "eventi consumati da")
```

## Note

- `Transaction`, il servizio di import e i servizi di matching/review
  vivono tutti dentro l'unico modulo `Reconciliation` — sono Application
  Service dello stesso bounded context, non moduli separati. Ogni
  relazione qui sopra resta dentro un unico `Container_Boundary`; non c'è
  alcuna domanda sulla direzione della dipendenza cross-modulo da porsi qui
  (a differenza di [ADR-001](../adr/ADR-001-modular-monolith_it.md), che
  copre confini cross-modulo *reali* come `Reconciliation` → `Settlement`).
- `TransactionId` è derivato in modo deterministico da `IdempotencyKey`
  (UUIDv5), non generato casualmente — vedi
  [ADR-006](../adr/ADR-006-deterministic-aggregate-id_it.md). È questo che
  fa collassare un import duplicato o concorrente sullo stesso aggregate
  invece di correre il rischio di crearne due.
- `ExpectedPayment` è Eloquent semplice, non event-sourced — vedi
  [ADR-002](../adr/ADR-002-hand-rolled-event-sourcing_it.md) per il perché
  solo `Transaction` usa l'event sourcing.
- `Read Model Projector` è infrastruttura, non dominio: non ha regole di
  business, solo un fold da evento a riga denormalizzata.
