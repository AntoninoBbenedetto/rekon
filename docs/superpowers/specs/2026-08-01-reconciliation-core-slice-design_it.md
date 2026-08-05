# Core Slice di Riconciliazione — Spec di Design

Stato: Approvato (scope v1)
Data: 2026-08-01

> Traduzione italiana dello spec approvato
> [2026-08-01-reconciliation-core-slice-design.md](2026-08-01-reconciliation-core-slice-design.md).
> In caso di divergenza, l'originale inglese fa fede.

## 1. Scopo e inquadramento

Questo è un progetto portfolio/dimostrativo. Il suo obiettivo è mostrare
competenze di system design, domain modeling e gestione dei fallimenti a
un pubblico di ingegneri backend **generalisti** — non spedire
un'integrazione PSP di produzione. Il dominio (riconciliazione di
pagamenti, stile PagoPA/PSP) viene mantenuto perché motiva naturalmente i
principi ingegneristici che il progetto vuole dimostrare: idempotenza,
stato esplicito, sicurezza sulla concorrenza, e tracciabilità.

La qualità del codice e la profondità su una slice ristretta contano più
dell'ampiezza delle funzionalità.

## 2. Scope della v1

Questo spec copre un'unica vertical slice completa: **importare un
estratto conto bancario, abbinarne le transazioni ai pagamenti attesi, e
riconciliarle**, con piena idempotenza, sicurezza sulla concorrenza, e un
audit trail immutabile.

**In scope:**
- Shared Kernel (fondamenta di dominio): infrastruttura di
  aggregate/eventi, value object.
- Modulo Reconciliation: import di estratto conto CSV, motore di matching,
  risoluzione tramite review manuale.
- Una REST API minimale come unica interfaccia (nessuna UI di
  amministrazione).

**Fuori scope per la v1** (esplicitamente rimandato, non progettato qui):
- Modulo Settlement (movimento di denaro / payout / ledger).
- Modulo Notification.
- Gli stati `Settled` e `Archived`.
- Expected Payment come modulo gestito — per la v1 sono dati seed/fixture
  (factory / un comando di seed), non un sottosistema CRUD.
- Formati reali di estratto conto (PagoPA XML, MT940). La v1 supporta solo
  un formato CSV custom.
- Autenticazione/autorizzazione sull'API — si assume un chiamante attendibile
  per la v1; vedi l'[ADR-008](../../adr/ADR-008-no-authentication-in-v1_it.md)
  per la decisione e il rischio che accetta.
- **Rilevamento delle frodi.** Il titolo originale del progetto diceva
  "Anti-Fraud"; qui non è progettato alcun scoring di frode, motore di regole
  o rilevamento di anomalie, e nulla in questa slice lo presuppone. Lo si
  nomina esplicitamente perché l'omissione sia una decisione e non una
  promessa non mantenuta.

## 3. Stack tecnico

- PHP 8.3+, Laravel 13
- PostgreSQL (event store + read model)
- Redis (code)
- Pest (testing)
- REST API (nessun pannello di amministrazione / nessun Filament — vedi
  §9)

Stile architetturale: Monolite Modulare, DDD. Moduli per la v1:
`SharedKernel`, `Reconciliation`. Ogni modulo possiede i propri layer
Domain / Application / Infrastructure. Import e matching sono due
Application Service (`ImportStatement`, `RunMatchingForTransaction`)
all'interno dell'unico modulo `Reconciliation`, non moduli separati —
condividono lo stesso bounded context, lo stesso ubiquitous language e lo
stesso aggregate (`Transaction`). La comunicazione cross-modulo (es. con i
futuri moduli `Settlement`/`Notification`) avviene tramite eventi di
dominio, non chiamate dirette; questa regola governa i confini tra moduli,
non le chiamate tra application service dello stesso modulo.

## 4. Fondamenta di dominio (Shared Kernel)

- **`AggregateRoot`** — classe base che gestisce uno stream di eventi in
  memoria, la versione corrente, e un pattern `apply()`/`record()` per
  produrre e ripiegare eventi.
- **`DomainEvent`** — interfaccia per tutti gli eventi: payload immutabile,
  `occurredAt`, `actor` (un value object che distingue `System` da
  un'identità chiamante API), `causationId` e `correlationId` (per
  tracciare quale comando/evento ha causato questo evento, e a quale
  processo di business appartiene).
- **Value Object:** `Money` (unità minime intere + `Currency`, mai float),
  `TransactionId`, `IdempotencyKey` (hash deterministico del contenuto
  sorgente — composizione esatta nell'[ADR-007](../../adr/ADR-007-idempotency-key-composition_it.md)).
  `TransactionId` non è generato casualmente: per l'aggregate
  `Transaction` è derivato in modo deterministico dalla sua
  `IdempotencyKey` tramite UUIDv5 (vedi §6.1 e
  [ADR-006](../../adr/ADR-006-deterministic-aggregate-id_it.md)), cosicché
  l'identità stessa dell'aggregate porta con sé la garanzia di idempotenza.
- **`EventStore`** — interfaccia di persistenza più un'implementazione
  PostgreSQL: una tabella append-only con chiave `(aggregate_id, version)`
  e vincolo di unicità, usata per il controllo di concorrenza ottimistica.

**Decisione — event sourcing scritto a mano, non un pacchetto** (es. non
`spatie/laravel-event-sourcing`): l'infrastruttura di ES qui è
intenzionalmente piccola (classe base dell'aggregate, event store, replay,
concorrenza ottimistica) e costruirla è essa stessa parte di ciò che il
progetto dimostra. È un trade-off di scope deliberato, specifico di un
progetto portfolio — la stessa decisione probabilmente andrebbe nella
direzione opposta su un sistema di produzione reale.

L'Event Sourcing è usato solo per l'aggregate `Transaction`. Gli Expected
Payment restano modelli Eloquent semplici (dati seed), poiché non sono
oggetto del design di macchina a stati/audit che viene dimostrato qui.

## 5. Macchina a stati della Transaction

Stati:

```
Pending → Matched     → Reconciled
        → Unmatched
        → NeedsReview → Reconciled
                      → Rejected
```

- `Pending`: la riga è arrivata, ha superato la validazione al confine ed è
  eleggibile per il job di matching. È lo stato in cui una `Transaction`
  nasce — una riga che non supera la validazione non diventa affatto una
  `Transaction`, quindi non serve uno stato separato di pre-validazione. (Una
  bozza precedente elencava uno stato `Imported` prima di `Pending`: è stato
  rimosso perché nessun evento lo lasciava mai — il §6.1 crea l'aggregate
  direttamente in `Pending`.)
- `Matched`: trovato esattamente un candidato con importo esatto; avanza
  automaticamente a `Reconciled`.
- `Unmatched`: nessun candidato trovato. Terminale per la v1 (nessun
  meccanismo di retry-later ancora progettato).
- `NeedsReview`: candidati multipli, o un candidato con importo non
  corrispondente (parziale). Richiede risoluzione manuale via API.
- `Reconciled`: confermata — stato finale in scope per la v1.
- `Rejected`: una transazione `NeedsReview` determinata manualmente come
  non corrispondente a nulla. Terminale.

Eventi di dominio: `TransactionImported`, `TransactionMatched`,
`TransactionMarkedUnmatched`, `TransactionMarkedAmbiguous`,
`TransactionReconciled`, `TransactionRejected`.

Le transizioni illegali (es. `Reconciled → Pending`) sono prevenute dentro
l'aggregate: ogni metodo comando verifica lo stato corrente prima di
registrare un evento, e altrimenti lancia un'eccezione di dominio.

## 6. Flusso end-to-end

1. **Import.** Un estratto conto CSV viene sottomesso (via API). Per ogni
   riga, viene derivata una `IdempotencyKey` dal suo contenuto —
   `reference + amount_minor_units + currency + statement_date +
   occurrence_index`, composizione e motivazioni nell'[ADR-007](../../adr/ADR-007-idempotency-key-composition_it.md)
   — e il `TransactionId` della riga viene derivato in modo deterministico da
   quella chiave (UUIDv5) — mai generato casualmente. L'import tenta sempre
   di creare l'aggregate `Transaction` e di fare append di
   `TransactionImported` alla expected version 0. Poiché lo stesso
   contenuto deriva sempre lo stesso `TransactionId`, risottomettere una
   riga già importata — o un duplicato realmente concorrente in race con
   questa — collide sul vincolo di unicità `(aggregate_id, version)`
   dell'event store; quel conflitto viene intercettato e trattato come
   no-op, non come errore (vedi
   [ADR-006](../../adr/ADR-006-deterministic-aggregate-id_it.md)).
   Altrimenti l'append va a buon fine, l'aggregate esiste in `Pending`, e
   viene dispatchato un job di matching per quella singola transazione. Una
   riga il cui append è collidito non dispatcha nulla — era già stata
   importata, e ha già una sua storia di job.
2. **Matching.** Il job in coda carica l'unica `Transaction` per cui è stato
   dispatchato e, se è ancora `Pending`, raccoglie gli Expected Payment
   candidati per riferimento, poi decide sull'importo:
   - 0 candidati → `TransactionMarkedUnmatched` → `Unmatched`.
   - esattamente 1 candidato, importo corrispondente esattamente →
     `TransactionMatched` → auto-confermata → `TransactionReconciled` →
     `Reconciled`.
   - esattamente 1 candidato, importo non corrispondente →
     `TransactionMarkedAmbiguous` (`reason: partial_amount_match`) →
     `NeedsReview`.
   - più di 1 candidato → `TransactionMarkedAmbiguous`
     (`reason: multiple_candidates`) → `NeedsReview`.

   **Il numero di candidati decide prima dell'importo.** Più candidati che
   condividono un riferimento *sono* di per sé l'ambiguità, e restano una
   decisione umana anche quando esattamente uno di essi corrisponde
   all'importo esatto — auto-riconciliare quel caso significherebbe scegliere
   in silenzio un vincitore fra pretese realmente in competizione sullo stesso
   denaro.
3. **Review manuale.** Per le transazioni `NeedsReview`, una chiamata API
   risolve il caso scegliendo un candidato (→ `Reconciled`) o rifiutandolo
   (→ `Rejected`).
4. **Proiezione.** Ogni evento viene proiettato in una tabella read model
   (stato corrente, versione, campi denormalizzati) per query veloci da
   parte dell'API. L'event store resta l'unica fonte di verità; il read
   model è usa e getta e ricostruibile tramite replay.

## 7. API (layer di interfaccia)

La REST API è l'unica interfaccia per la v1 — nessun pannello di
amministrazione. Superficie indicativa (route/payload esatti sono un
dettaglio implementativo per il piano, non per questo spec):

- `POST /imports` — sottomette un estratto conto CSV.
- `GET /transactions?state=...` — elenca le transazioni, filtrabili per
  stato.
- `GET /transactions/{id}` — dettaglio della transazione, incluso il suo
  intero storico eventi (funge anche da vista dell'audit trail).
- `POST /transactions/{id}/resolve` — risolve una transazione
  `NeedsReview` (scegliendo un candidato o rifiutando).

Nessuna autenticazione/autorizzazione in v1 — chiamante assunto attendibile
([ADR-008](../../adr/ADR-008-no-authentication-in-v1_it.md)).

## 8. Gestione dei fallimenti

Ogni failure mode dei principi ingegneristici del progetto è affrontata da
un meccanismo specifico, non da gestione generica degli errori:

- **Estratto conto/webhook duplicato:** il `TransactionId` della
  `Transaction` è derivato in modo deterministico dalla sua
  `IdempotencyKey` (UUIDv5), quindi risottomettere lo stesso contenuto —
  in sequenza o in concorrenza — punta sempre allo stesso aggregate. Il
  vincolo di unicità `(aggregate_id, version)` dell'event store arbitra
  qualunque race; il conflitto di chi perde viene trattato come no-op
  (vedi [ADR-006](../../adr/ADR-006-deterministic-aggregate-id_it.md)).
- **Import parziale / timeout di rete:** ogni riga CSV viene elaborata ed
  è idempotente indipendentemente; il job di import nel suo complesso è
  sicuro da rieseguire.
- **Deadlock del database / esecuzione concorrente:** nessun lock
  pessimista a lunga durata. Concorrenza ottimistica sulla versione
  dell'aggregate — l'append di eventi è condizionato a `expected_version`,
  i conflitti vengono ritentati dal chiamante.
- **Retry della coda (esecuzione duplicata del job):** il matching job
  verifica lo stato corrente prima di agire; riprocessare una transazione
  già `Reconciled` è un no-op.

## 9. Perché non Filament (o un altro pannello di amministrazione)

Considerato e scartato per la v1: Filament è in gran parte configurazione
dichiarativa sopra Eloquent — costruire un pannello di amministrazione con
esso dimostra competenza sul pacchetto più di quanto dimostri il design
di dominio/API che questo progetto vuole mostrare a un pubblico backend
generalista. Una REST API mantiene il 100% dello sforzo sui layer che il
progetto vuole dimostrare, e mantiene più piccola l'impronta delle
dipendenze.

Una UI di review sottile e custom (es. una piccola pagina Livewire per la
coda `NeedsReview`) è un plausibile follow-up una volta che API e dominio
sono solidi, puramente per scopi demo (registrazioni schermo,
walkthrough dal vivo). È stata intenzionalmente rimandata fuori da questo
spec perché è puro lavoro di layer di interfaccia che non tocca il design
del dominio.

## 10. Strategia di testing (Pest)

- **Test unitari dell'aggregate**, stile given/when/then: dati eventi
  precedenti, quando un comando viene applicato, allora vengono registrati
  eventi specifici (o viene lanciata un'eccezione di dominio per le
  transizioni illegali).
- **Test di idempotenza:** importare la stessa riga due volte produce
  esattamente un evento `TransactionImported` — e, nella direzione opposta,
  un estratto conto contenente due righe identiche su ogni campo produce
  **due** aggregate, e risottometterlo non ne produce un terzo
  ([ADR-007](../../adr/ADR-007-idempotency-key-composition_it.md)).
- **Test di concorrenza:** simulare un conflitto di versione sull'append
  e verificare il comportamento di retry/conflitto.
- **Test di transizione illegale:** uno per ogni transizione di stato non
  valida.
- **Feature test:** percorso completo dall'import CSV, attraverso il
  matching, fino a una transazione riconciliata o rifiutata, via API.

## 11. Esplicitamente fuori scope / lavori futuri

- Moduli Settlement, Notification; stati `Settled`/`Archived`.
- Expected Payment come vero modulo gestito (API di creazione, lifecycle).
- Formati reali di estratto conto (PagoPA, MT940).
- Rilevamento delle frodi, in qualsiasi forma (§2).
- Autenticazione/autorizzazione dell'API ([ADR-008](../../adr/ADR-008-no-authentication-in-v1_it.md)).
- Snapshot dell'event store (non necessari ai volumi di dati della v1; da
  rivedere se il costo del replay diventasse un problema).
- Una UI di review sottile (vedi §9).
