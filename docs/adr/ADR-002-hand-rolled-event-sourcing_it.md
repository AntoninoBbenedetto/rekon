# ADR-002: Infrastruttura di Event Sourcing scritta a mano invece di un pacchetto

Stato: Accettato
Data: 2026-08-01

## Contesto

L'aggregate `Transaction` richiede event sourcing: una storia append-only di
cosa è successo, replayabile per ricostruire lo stato corrente, con
controllo di concorrenza ottimistica sull'append. L'ecosistema Laravel ha
pacchetti maturi per questo, in particolare `spatie/laravel-event-sourcing`,
che fornirebbe out-of-the-box aggregate root, projector, reactor e uno
store.

L'obiettivo dichiarato di questo repository (`PROJECT_CONTEXT.md`,
"Obiettivi del Repository") è dimostrare competenze di system design,
domain modeling e gestione dei fallimenti.

## Decisione

Costruire a mano l'infrastruttura di event sourcing: una classe base
minimale `AggregateRoot` (stream di eventi in memoria, tracciamento della
versione, `apply()`/`record()`), un'interfaccia `DomainEvent`, e un
`EventStore` con implementazione PostgreSQL basata su una tabella
append-only con chiave `(aggregate_id, version)` e vincolo di unicità per la
concorrenza ottimistica.

**Non** usare `spatie/laravel-event-sourcing` o un pacchetto equivalente
per la v1.

## Conseguenze

**Positive:**
- Gli interni dell'event sourcing — la parte del sistema che dimostra più
  direttamente i principi "tracciabilità" e "stato esplicito" — sono
  visibili e revisionabili in questo codebase, non nascosti dentro una
  dipendenza.
- Nessun accoppiamento alle opinioni di un pacchetto specifico su projector,
  snapshotting o serializzazione degli eventi, prima che questo progetto
  abbia deciso di cosa ha davvero bisogno.
- Controllo completo sulla semantica esatta dei conflitti di append
  necessaria per [ADR-003](ADR-003-optimistic-concurrency_it.md).

**Negative / trade-off accettati:**
- Più codice da scrivere e mantenere rispetto ad adottare un pacchetto
  maturo — nessuna pipeline di projector asincroni collaudata, nessuno
  snapshotting, nessuna strategia di upcasting per l'evoluzione dello schema
  degli eventi. Esplicitamente rimandati (vedi lo spec della core slice,
  §11).
- Questo è un trade-off **specifico di un progetto portfolio**. Su un
  sistema di produzione reale che gestisce dati finanziari veri, la stessa
  analisi favorirebbe probabilmente un pacchetto collaudato: reinventare gli
  interni di un event store aggiunge rischio (bug di concorrenza sottili,
  edge case mancanti) che una dipendenza matura ha già affrontato. Questa
  decisione non va letta come una raccomandazione generale contro i
  pacchetti di ES.

**Da rivedere se:** questo progetto passa da portfolio/demo a un vero
deployment in produzione — a quel punto, rivalutare
`spatie/laravel-event-sourcing` o un equivalente.
