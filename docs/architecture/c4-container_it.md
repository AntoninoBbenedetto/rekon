# C4 — Container (v1)

Le unità deployabili/di runtime che compongono il sistema.

```mermaid
C4Container
    title Diagramma dei Container — Core Slice di Riconciliazione (v1)

    Person(caller, "Chiamante API", "Client attendibile, nessuna autenticazione in v1")

    Container_Boundary(app, "Motore di Riconciliazione (Monolite Modulare Laravel)") {
        Container(api, "REST API", "Laravel (PHP 8.3)", "Gestisce import, query sulle transazioni e risoluzione della review manuale. Vedi ADR-001, ADR-004.")
        Container(worker, "Matching Worker", "Laravel Queue Worker (PHP 8.3)", "Consuma un job di matching per transazione importata; la confronta con gli Expected Payment se è ancora Pending.")
    }

    ContainerDb(db, "PostgreSQL", "PostgreSQL", "Event store (fonte di verità) + read model (proiettati, usa e getta) + dati seed degli Expected Payment.")
    ContainerQueue(queue, "Redis", "Redis", "Backend di coda per il matching job. Vedi failures/retry-strategy_it.md per la gestione della redelivery.")

    Rel(caller, api, "Import CSV, query sulle transazioni, risoluzione NeedsReview", "REST/JSON")
    Rel(api, db, "Append eventi, lettura proiezioni", "SQL")
    Rel(api, queue, "Accoda un matching job per ogni TransactionImported appesa con successo", "Protocollo Redis")
    Rel(queue, worker, "Consegna il matching job (at-least-once)", "Protocollo Redis")
    Rel(worker, db, "Replay dello stream della transazione + lettura Expected Payment, append eventi match/unmatch/ambiguous", "SQL")
```

## Note

- **Un solo database PostgreSQL**, non uno per modulo — conseguenza
  deliberata della decisione di Monolite Modulare
  ([ADR-001](../adr/ADR-001-modular-monolith_it.md)): i confini tra moduli
  sono imposti nel codice, non da schemi o database separati, in questa
  fase.
- **Redis è solo per la coda** in v1 — nessun layer di cache progettato
  ancora; aggiungerne uno in futuro non cambia la forma di questo diagramma,
  solo a cosa viene usato Redis.
- Il matching worker è disegnato come container distinto dall'API perché
  gira come processo di worker su coda indipendente, anche se il suo codice
  vive nello stesso deployable/modulo (`Reconciliation`) — vedi
  [c4-component_it.md](c4-component_it.md) per dove vive esattamente la
  classe del job.
- **Un job per transazione, dispatchato all'import** — non una scansione
  periodica della tabella dei `Pending`. Il dispatch avviene solo dopo che
  l'append di `TransactionImported` è andato a buon fine, quindi un import
  duplicato (che collide e fa no-op, per
  l'[ADR-006](../adr/ADR-006-deterministic-aggregate-id_it.md)) non accoda
  nulla.
- La consegna at-least-once dalla coda è il motivo per cui il matching job
  deve essere idempotente rispetto allo stato della transazione — vedi
  [failures/retry-strategy_it.md](../failures/retry-strategy_it.md).
