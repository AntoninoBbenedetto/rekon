# Core Slice di Riconciliazione — Addendum di Design Tecnico

Stato: Approvato
Data: 2026-08-01 (revisionato il 2026-08-03)

## 0. Relazione con lo spec approvato

Questo documento riempie i dettagli che
[2026-08-01-reconciliation-core-slice-design_it.md](2026-08-01-reconciliation-core-slice-design_it.md)
(stato: Approvato) ha deliberatamente lasciato aperti — citando
direttamente: "route/payload esatti sono un dettaglio implementativo per
il piano, non per questo spec" (§7), e nessuna struttura di cartelle o
schema DB è specificato lì.

Questo addendum non cambia scope, stati, eventi, o alcuna decisione presa
nello spec approvato. Dove i due documenti sono in disaccordo, vince lo
spec approvato e questo documento è sbagliato e va corretto. Questo
addendum aggiunge dettaglio; non ridecide nulla di già deciso in
[ADR-001](../../adr/ADR-001-modular-monolith_it.md) fino a
[ADR-008](../../adr/ADR-008-no-authentication-in-v1_it.md).

È `Approvato` e non più `Bozza` perché
l'[ADR-005](../../adr/ADR-005-csv-only-ingestion-v1_it.md) (a sua volta
Accettato) lo cita come autorità sullo schema CSV — una decisione accettata
non può poggiare su una bozza.

## 1. Struttura moduli / cartelle

```
app/
└── Modules/
    ├── SharedKernel/
    │   ├── Domain/
    │   │   ├── AggregateRoot.php
    │   │   ├── DomainEvent.php          (interfaccia)
    │   │   ├── Actor.php                (value object: System | ApiCaller)
    │   │   ├── Money.php
    │   │   ├── Currency.php
    │   │   ├── TransactionId.php        (deriveFrom(IdempotencyKey): UUIDv5 — vedi ADR-006)
    │   │   └── IdempotencyKey.php
    │   ├── Application/
    │   │   └── EventStore.php           (interfaccia: append(), loadStream())
    │   └── Infrastructure/
    │       └── PostgresEventStore.php
    │
    └── Reconciliation/
        ├── Domain/
        │   ├── Transaction.php          (aggregate root; estende SharedKernel\AggregateRoot)
        │   ├── TransactionState.php     (enum)
        │   ├── ExpectedPayment.php      (modello Eloquent semplice — ADR-002)
        │   └── Events/
        │       ├── TransactionImported.php
        │       ├── TransactionMatched.php
        │       ├── TransactionMarkedUnmatched.php
        │       ├── TransactionMarkedAmbiguous.php
        │       ├── TransactionReconciled.php
        │       └── TransactionRejected.php
        ├── Application/
        │   ├── ImportStatementService.php
        │   ├── ImportStatementRow.php   (DTO)
        │   ├── MatchTransactionService.php
        │   └── ResolveReviewService.php
        └── Infrastructure/
            ├── CsvStatementParser.php
            ├── MatchPendingTransactionsJob.php
            ├── TransactionReadModelProjector.php
            └── Http/
                ├── ImportsController.php
                ├── TransactionsController.php
                └── ResolveTransactionController.php
```

Note:
- `Ingestion` e `Matching` non sono moduli separati: sono i nomi di due
  Application Service (`ImportStatementService`,
  `MatchTransactionService`/`ResolveReviewService`) dentro l'unico modulo
  `Reconciliation`. Entrambi operano direttamente sullo stesso tipo di
  dominio `Transaction` — non c'è alcuna dipendenza cross-modulo da
  gestire, e nessuna eccezione sulla direzione della dipendenza è
  necessaria (vedi [c4-component_it.md](../../architecture/c4-component_it.md)).
- `ImportStatementService` deriva la `IdempotencyKey` di ogni riga, poi
  chiama `TransactionId::deriveFrom($idempotencyKey)` per ottenere
  l'identità dell'aggregate prima di crearlo — vedi
  [ADR-006](../../adr/ADR-006-deterministic-aggregate-id_it.md). Derivare la
  chiave richiede `occurrence_index`
  ([ADR-007](../../adr/ADR-007-idempotency-key-composition_it.md)), che è
  definito rispetto all'intero estratto conto — quindi `CsvStatementParser`
  deve raggruppare le righe per (`reference`, `amount_minor_units`,
  `currency`, `statement_date`) e numerare i duplicati dentro ogni gruppo
  prima che le chiavi vengano derivate. Le righe non possono essere
  chiavizzate una alla volta mentre scorrono.
- Su un append andato a buon fine, `ImportStatementService` dispatcha un
  `MatchPendingTransactionJob` per quella transazione. Una riga il cui append
  è collidito (già importata) non dispatcha nulla.
- I controller sono sottili: traducono solo HTTP ⇄ chiamate ai servizi
  applicativi. Nessuna logica di business nei controller
  (`PROJECT_CONTEXT.md` §5).

## 2. Schema del database

### `event_store`

L'unica fonte di verità. Append-only.

| colonna           | tipo          | note                                              |
|--------------------|---------------|-----------------------------------------------------|
| `id`                | bigint PK     | surrogato, solo per ordinamento/paginazione         |
| `aggregate_id`      | uuid          | `TransactionId`                                     |
| `version`           | integer       | base 1, per aggregate                               |
| `event_type`        | text          | es. `transaction.imported`                          |
| `schema_version`    | smallint      | versione della forma di `payload` per questo `event_type`; parte da `1` |
| `payload`           | jsonb         | campi specifici dell'evento (vedi §3)                |
| `occurred_at`       | timestamptz   |                                                       |
| `actor_type`        | text          | `system` \| `api_caller`                             |
| `actor_id`          | text, nullable| identità quando `actor_type = api_caller`            |
| `causation_id`      | uuid          | id del comando/evento che ha causato questo evento   |
| `correlation_id`    | uuid          | id condiviso da un intero processo di business       |
| `recorded_at`       | timestamptz   | assegnato dal server, può differire da `occurred_at` |

Vincoli:
- `UNIQUE (aggregate_id, version)` — è su questo che si basa il controllo
  di concorrenza ottimistica
  ([ADR-003](../../adr/ADR-003-optimistic-concurrency_it.md)).
- L'append è l'unica scrittura supportata. Nessun `UPDATE`/`DELETE` nel
  codice applicativo contro questa tabella.

Su `schema_version`: la v1 scrive `1` ovunque e non esiste alcun upcaster —
l'evoluzione degli schemi degli eventi è rimandata
([ADR-002](../../adr/ADR-002-hand-rolled-event-sourcing_it.md)). La colonna è
comunque presente fin dalla prima migrazione, perché è l'unico pezzo di questo
design che non si può retrofittare a poco prezzo: aggiungerla dopo significa
riempire a posteriori righe la cui vera versione di schema può solo essere
indovinata dalla loro forma. Rimandare il *meccanismo* va bene; rimandare il
*marcatore* no.

Indici: `(aggregate_id, version)` (coperto dal vincolo di unicità, serve
anche le query di caricamento dello stream).

### `transactions_read_model`

Proiezione usa e getta, ricostruibile rifacendo il replay di
`event_store`.

| colonna                        | tipo        | note                                          |
|----------------------------------|-------------|-------------------------------------------------|
| `transaction_id`                 | uuid PK     | = `aggregate_id`                                |
| `state`                          | text        | `TransactionState` corrente                     |
| `version`                        | integer     | versione dell'ultimo evento applicato, per controlli di staleness |
| `amount_minor_units`             | bigint      | da `Money`                                      |
| `currency`                       | text        | ISO 4217                                        |
| `reference`                      | text        | campo di riferimento dell'estratto conto, usato nel matching |
| `statement_date`                 | date        | data valuta dalla riga CSV; fa parte della chiave di idempotenza (ADR-007) |
| `matched_expected_payment_id`    | uuid, nullable | impostato una volta abbinata/riconciliata    |
| `imported_at`                    | timestamptz |                                                  |
| `updated_at`                     | timestamptz | ultima scrittura della proiezione               |

Indici: `state` (supporta `GET /transactions?state=...`), `reference`.

### `expected_payments`

Tabella Eloquent semplice, dati seed/fixture (spec della core slice §2,
§4).

| colonna              | tipo        | note                          |
|------------------------|-------------|---------------------------------|
| `id`                    | uuid PK     |                                  |
| `amount_minor_units`    | bigint      |                                  |
| `currency`              | text        |                                  |
| `reference`             | text        | confrontato con il reference della Transaction |
| `created_at` / `updated_at` | timestamptz | timestamp Eloquent standard |

## 3. Payload degli eventi

Tutti gli eventi portano in aggiunta i campi della busta di `DomainEvent`
(`occurredAt`, `actor`, `causationId`, `correlationId`) — sotto è mostrato
solo il `payload` specifico dell'evento.

Ogni campo qui sotto viene scritto dal dominio e riletto in fase di replay.
Nulla è decorativo: se un campo è in un payload, è perché qualche domanda a cui
l'audit trail deve rispondere — *cosa è stato abbinato, perché, da chi, su
decisione di chi* — non è rispondibile senza di esso.

**`TransactionImported`**
```json
{
  "transaction_id": "uuid",
  "amount_minor_units": 12345,
  "currency": "EUR",
  "reference": "string",
  "statement_date": "2026-07-31",
  "occurrence_index": 0,
  "idempotency_key": "sha256:...",
  "raw_row_checksum": "sha256:..."
}
```
`statement_date` e `occurrence_index` sono le due componenti della chiave non
altrimenti visibili sull'evento
([ADR-007](../../adr/ADR-007-idempotency-key-composition_it.md)); portarle
rende la derivazione di `idempotency_key` — e quindi di `transaction_id` —
riproducibile a partire dal solo stream di eventi.

`raw_row_checksum` è lo SHA-256 della **riga CSV grezza e non normalizzata**
così come ricevuta, distinto da `idempotency_key` (che hasha campi
selezionati e normalizzati). È forense: fissa ciò che il file sorgente diceva
letteralmente, così una contestazione può essere risolta contro i byte
originali anche dopo che le regole di normalizzazione sono cambiate. Non viene
mai usato per deduplicazione, matching o derivazione dell'ID.

**`TransactionMatched`**
```json
{
  "transaction_id": "uuid",
  "expected_payment_id": "uuid",
  "match_type": "exact"
}
```

**`TransactionMarkedUnmatched`**
```json
{
  "transaction_id": "uuid",
  "reason": "no_candidate_found"
}
```

**`TransactionMarkedAmbiguous`**
```json
{
  "transaction_id": "uuid",
  "candidate_expected_payment_ids": ["uuid", "..."],
  "reason": "multiple_candidates" 
}
```
`reason` è `multiple_candidates` (più di un candidato condivideva il
riferimento — indipendentemente dal fatto che uno di essi corrispondesse
esattamente all'importo) oppure `partial_amount_match` (esattamente un
candidato, importo diverso). I due non sono intercambiabili: chi revisiona il
caso deve sapere se sta scegliendo fra pretese in competizione o accettando
una discrepanza di importo. Lo spec della core slice §6 definisce quale dei
due prevale.

**`TransactionReconciled`**
```json
{
  "transaction_id": "uuid",
  "expected_payment_id": "uuid",
  "resolution": "auto" 
}
```
`resolution` è `auto` (diretto da `Matched`) oppure `manual` (da
`NeedsReview` tramite l'endpoint di resolve).

**`TransactionRejected`**
```json
{
  "transaction_id": "uuid",
  "reason": "string"
}
```
`reason` è il testo libero fornito dal chiamante dall'endpoint di resolve
(§4), ed è **obbligatorio** — un rifiuto è la decisione umana di stralciare un
pagamento candidato, e un audit trail che registra la decisione senza la
giustificazione risponde a "cosa è successo?" ma non a "perché?"
(`PROJECT_CONTEXT.md` §3). L'API deve rifiutare un'azione `reject` con reason
vuota (`422`) invece di persistere un evento incapace di spiegarsi.

## 4. Contratti API

Nessuna autenticazione in v1
([ADR-008](../../adr/ADR-008-no-authentication-in-v1_it.md)). Tutte
le risposte `application/json`.

### `POST /imports`

Request: `multipart/form-data`, campo `file` — l'estratto conto CSV.

Colonne CSV (formato custom v1 —
[ADR-005](../../adr/ADR-005-csv-only-ingestion-v1_it.md)):
`reference,amount_minor_units,currency,statement_date`.

Response `200 OK`:
```json
{
  "correlation_id": "uuid",
  "rows_received": 42,
  "rows_imported": 40,
  "rows_already_imported": 2,
  "transaction_ids": ["uuid", "..."]
}
```
L'import viene elaborato riga per riga in modo sincrono all'interno della
richiesta, secondo il flusso dello spec approvato (§6.1): la risposta descrive
quindi lavoro già avvenuto — `200`, non `202`. E nemmeno `201`: la richiesta
crea molte risorse, non una, e non esiste una singola `Location` da indicare.

`correlation_id` è il vero `correlationId` impresso su ogni evento prodotto da
questo import, quindi è una maniglia di audit utilizzabile: raggruppa l'intero
processo di business nell'event store. (Una bozza precedente restituiva invece
un `import_id` — rimosso, perché non esiste alcuna risorsa `imports` contro
cui risolverlo. Se un giorno si volesse un tracciamento dell'import a livello
di estratto conto, servirebbe un aggregate dedicato: è una decisione di
design, non un campo di risposta.)

`rows_already_imported` conta le righe il cui append è collidito sul vincolo
di unicità dell'event store — il percorso idempotente di no-op dell'
[ADR-006](../../adr/ADR-006-deterministic-aggregate-id_it.md). Risottomettere
un estratto conto identico è quindi un `200` con
`rows_imported: 0, rows_already_imported: 42`, che è un successo, non un
errore.

Errori: `422` con un elenco di errori per riga se il CSV è strutturalmente
non valido (colonne mancanti). Una riga con contenuto errato non fa
fallire l'intera richiesta — vedi la gestione del fallimento da import
parziale in
[failures/network-timeout_it.md](../../failures/network-timeout_it.md) e
spec della core slice §8.

### `GET /transactions?state={state}`

`state` opzionale, uno dei valori di `TransactionState`. Omesso = tutti
gli stati.

Response `200`:
```json
{
  "data": [
    {
      "id": "uuid",
      "state": "NeedsReview",
      "amount_minor_units": 12345,
      "currency": "EUR",
      "reference": "string",
      "statement_date": "2026-07-31",
      "imported_at": "2026-08-01T10:00:00Z"
    }
  ]
}
```

### `GET /transactions/{id}`

Response `200`:
```json
{
  "id": "uuid",
  "state": "Reconciled",
  "amount_minor_units": 12345,
  "currency": "EUR",
  "reference": "string",
  "version": 3,
  "history": [
    {
      "event_type": "transaction.imported",
      "occurred_at": "2026-08-01T10:00:00Z",
      "actor": { "type": "api_caller", "id": "..." },
      "causation_id": "uuid",
      "correlation_id": "uuid",
      "payload": { "...": "..." }
    }
  ]
}
```
`history` è l'intero stream di eventi ordinato — questo endpoint funge
anche da vista dell'audit trail (spec della core slice §7).

Errori: `404` se non esiste alcun aggregate per `{id}`.

### `POST /transactions/{id}/resolve`

Valido solo quando la transazione è in stato `NeedsReview`.

Request (scegliere un candidato):
```json
{ "action": "confirm", "expected_payment_id": "uuid" }
```
Request (rifiutare):
```json
{ "action": "reject", "reason": "string" }
```

Response `200`: stessa forma di `GET /transactions/{id}`, che riflette il
nuovo stato.

Errori:
- `409 Conflict` — la transazione non è attualmente `NeedsReview`
  (transizione illegale, oppure una risoluzione concorrente è già
  avvenuta — vedi
  [ADR-003](../../adr/ADR-003-optimistic-concurrency_it.md)). Il body
  include lo stato corrente così il chiamante può decidere se ritentare.
- `422` — `expected_payment_id` non è tra i candidati registrati su
  `TransactionMarkedAmbiguous`, oppure un'azione `reject` con `reason`
  mancante/vuota (§3).

## 5. Punti aperti per il piano di implementazione

Deliberatamente lasciati al piano di implementazione eseguibile
(superpowers:writing-plans), non decisi qui:
- Firme esatte di enum/classi PHP.
- Naming/ordinamento dei file di migration.
- Se il projector del read model gira in modo sincrono nella richiesta o
  tramite un listener in coda (entrambe sono coerenti con questo design;
  è una scelta di performance/finestra di consistenza, non di dominio).
- Libreria/approccio di validazione per il contenuto delle righe CSV
  (`Amount`, `Currency` secondo `PROJECT_CONTEXT.md` §"Sicurezza").
