# Panoramica Architetturale

## Moduli in scope (v1)

```
SharedKernel
  └── possiede: AggregateRoot, DomainEvent, EventStore, Value Object (Money,
      TransactionId — derivato in modo deterministico da IdempotencyKey,
      vedi ADR-006 — e IdempotencyKey stesso). Nessuna regola di business
      propria — pura infrastruttura di dominio condivisa dagli altri
      moduli.

Reconciliation
  └── possiede: parsing dell'estratto conto CSV, idempotenza per riga,
      l'aggregate Transaction e la sua intera macchina a stati, il motore
      di matching (job in coda), confronto con gli Expected Payment, e
      risoluzione della review manuale. Pubblica TransactionImported,
      TransactionMatched, TransactionMarkedUnmatched,
      TransactionMarkedAmbiguous, TransactionReconciled,
      TransactionRejected. Organizzato internamente come due Application
      Service — import e matching — che condividono un unico layer
      Domain, non due moduli: operano sullo stesso aggregate Transaction e
      sullo stesso ubiquitous language, quindi sono un unico bounded
      context (vedi ADR-001).
```

`Settlement`, `Notification`, e gli stati `Settled`/`Archived` sono fuori
scope per la v1 (vedi lo [spec della core slice](../superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
§2) e non sono progettati qui.

Ogni modulo possiede i propri layer Domain / Application / Infrastructure
(vedi [ADR-001](../adr/ADR-001-modular-monolith_it.md)). Nessun modulo
accede direttamente alla persistenza o alle classi interne di un altro
modulo.

## Come comunicano i moduli

I moduli comunicano tramite **eventi di dominio**, non chiamate dirette a
metodi attraverso i confini dei moduli. Questa regola governa come
`Reconciliation` parlerebbe con i futuri moduli — `Settlement` che reagisce
a `TransactionReconciled`, ad esempio — tramite eventi, mai chiamando
direttamente gli interni di `Reconciliation`.

Non governa invece come il servizio di import e quello di matching si
parlano tra loro, perché non sono moduli separati: sono entrambi
Application Service dentro `Reconciliation`, ed entrambi chiamano
direttamente il tipo di dominio `Transaction` (i suoi metodi comando —
`import()`, `match()`, `markUnmatched()`, ...). Il servizio di import non
ha bisogno di pubblicare un evento perché il matching job "scopra" una
nuova transazione attraverso un confine di modulo che non esiste: avendo
appeso con successo `TransactionImported`, dispatcha direttamente un job di
matching per quella singola transazione. `Transaction` stessa — l'unico
aggregate del sistema — è posseduta interamente da `Reconciliation`: una sola
classe, un solo insieme di definizioni di evento, non divisa tra due moduli.
Vedi il [diagramma dei componenti](c4-component_it.md) per sapere esattamente
dove.

**Il matching è dispatchato per transazione, non pollato a lotti.** Viene
accodato un job per ogni riga importata con successo; il job carica quella
transazione, verifica che sia ancora `Pending`, e agisce. Una riga il cui
import è collidito sul vincolo di idempotenza non accoda nulla. È questo il
modello su cui ragiona
[failures/retry-strategy_it.md](../failures/retry-strategy_it.md): la consegna
at-least-once della coda implica che un job possa girare due volte per la
stessa transazione, e a rendere la cosa sicura è la guardia di stato
dell'aggregate, non uno scheduler.

## Event store e read model

Lo stato di `Transaction` non viene mai letto da una riga mutabile. Ogni
comando (`import`, `match`, `markUnmatched`, `markAmbiguous`, `reconcile`,
`reject`) carica l'aggregate ripercorrendo (replay) il suo stream di
eventi dall'`EventStore`, verifica che lo stato corrente permetta la
transizione, e appende un nuovo evento.

Un **read model** (tabella denormalizzata, stato corrente + versione +
campi chiave) viene proiettato da ogni evento appeso, puramente per
rendere veloci le query di `GET /transactions`. Il read model è usa e
getta: può essere eliminato e ricostruito rifacendo il replay dell'event
store da zero. L'event store è l'unica fonte di verità.

```
 riga CSV → servizio di import → [stream di eventi Transaction] → projector → read model
                                        ↑ appeso da ↓
                              matching job / review manuale
```

## Dove andare adesso

- [c4-context_it.md](c4-context_it.md) — confine del sistema e attori
  esterni.
- [c4-container_it.md](c4-container_it.md) — unità deployabili (app, DB,
  coda).
- [c4-component_it.md](c4-component_it.md) — interni di ogni modulo.
- [../adr/](../adr/) — perché sono state fatte queste scelte, con le
  alternative considerate.
- [../failures/](../failures/) — come viene gestita ogni failure mode
  attesa.
- [../glossary_it.md](../glossary_it.md) — terminologia di dominio usata
  in tutto il progetto.
- [../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design_it.md](../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design_it.md) —
  schema DB, payload degli eventi, contratti API.
