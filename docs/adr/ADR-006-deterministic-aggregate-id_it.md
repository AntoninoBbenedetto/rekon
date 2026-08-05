# ADR-006: ID di aggregate deterministici derivati dalla chiave di idempotenza

Stato: Accettato
Data: 2026-08-03

## Contesto

Importare una riga CSV deve essere sicuro da ripetere: lo stesso estratto
conto risottomesso dopo un timeout, un ri-upload manuale, o due richieste
realmente concorrenti per lo stesso contenuto devono collassare tutte in un
unico aggregate `Transaction` ("Idempotency First", `PROJECT_CONTEXT.md`
§1; "esecuzione concorrente" e "estratto conto duplicato" come failure mode
richiesti, §2).

Il meccanismo originariamente implicito nel design era: generare un
`TransactionId` casuale (UUIDv4) durante l'import di una riga, e verificare
preventivamente se esiste già uno stream di aggregate per la
`IdempotencyKey` di quella riga (un hash deterministico del suo contenuto).
Se esiste, trattare la riga come già importata; altrimenti creare un nuovo
aggregate.

Questa sequenza "verifica-poi-agisci" ha una finestra di race: due import
concorrenti dello stesso contenuto possono entrambi osservare "non ancora
importato" prima che una delle due scritture vada a buon fine, e procedere
entrambi — ciascuno creando il proprio aggregate con il proprio
`TransactionId` casuale. I due aggregate sono indipendenti agli occhi
dell'event store, quindi nulla rifiuta il secondo. Questo riproduce
esattamente il failure mode "estratto conto duplicato" che il sistema deve
prevenire, in particolare sotto concorrenza.

In particolare, [`failures/duplicated-statement_it.md`](../failures/duplicated-statement_it.md)
affermava già che "il vincolo di unicità `(aggregate_id, version)`
dell'event store fa sì che anche una race tra due import concorrenti della
stessa riga non possa produrre due stream indipendenti." Questa
affermazione è vera solo se i duplicati concorrenti puntano garantitamente
allo stesso `aggregate_id` — cosa che la generazione casuale dell'ID non
garantisce. Il documento descriveva un risultato che il meccanismo, così
come progettato, non otteneva davvero.

## Decisione

Derivare l'identità dell'aggregate `Transaction` in modo deterministico dal
suo contenuto, invece di generarla casualmente:

`TransactionId::deriveFrom(IdempotencyKey $key): TransactionId`, calcolato
come UUIDv5 a partire da un namespace UUID fisso dell'applicazione e dal
valore della chiave di idempotenza. `TransactionId::generate()` (UUIDv4
casuale) viene rimosso — `Transaction` è l'unico aggregate nella v1, e ogni
`Transaction` viene creata a partire da una `IdempotencyKey`, quindi un
percorso a identità casuale è codice morto per costruzione.

L'import non verifica più l'esistenza prima di scrivere. Deriva sempre il
`TransactionId` dalla `IdempotencyKey` della riga e tenta
`EventStore::append($transactionId, expectedVersion: 0, [TransactionImported])`.
Un conflitto su quell'append (il vincolo di unicità `(aggregate_id,
version)` che rifiuta una seconda riga con `version = 1`) significa che il
contenuto era già stato importato — da una richiesta precedente o da una
realmente concorrente — e viene gestito come no-op, non come errore.

## Conseguenze

**Positive:**
- Elimina del tutto la finestra di race del check-then-act: la correttezza
  non dipende più da una lettura (verifica di esistenza) coerente con una
  scrittura concorrente non ancora committata.
- Riusa il meccanismo di concorrenza già stabilito da
  [ADR-003](ADR-003-optimistic-concurrency_it.md) (vincolo di unicità +
  `expected_version`) come unico arbitro della race di idempotenza, invece
  di introdurre un secondo meccanismo di deduplicazione indipendente.
- Rende vera l'affermazione già presente in
  [`failures/duplicated-statement_it.md`](../failures/duplicated-statement_it.md),
  invece che aspirazionale.
- Semplifica il percorso di import: un solo tentativo di scrittura
  incondizionato, nessuna query di verifica esistenza prima.

**Negative / trade-off accettati:**
- Gli ID di aggregate `Transaction` non sono più opachi/casuali — un
  `TransactionId` è ricostruibile dal contenuto della riga CSV, noti il
  namespace UUID e lo schema di hashing. Non è una preoccupazione di
  sicurezza per la v1 (nessun confine di autorizzazione dipende
  dall'inintuibilità dell'ID, e non c'è alcuna autenticazione ancora — vedi
  [ADR-004](ADR-004-rest-api-only-no-admin-panel_it.md)), ma è una
  considerazione reale se il progetto dovesse mai richiedere identificatori
  di transazione inintuibili.
- L'identità dell'aggregate è ora accoppiata alla definizione di
  `IdempotencyKey` (quali campi vengono hashati). Cambiare quella
  definizione in futuro cambierebbe il `TransactionId` derivato per la
  stessa riga reale — una preoccupazione di migrazione per dati già
  importati, non una preoccupazione per la v1 poiché non ne esistono
  ancora.
- Questa è una scelta specifica di `Transaction`, non uno stile della casa:
  si applica perché "stesso contenuto due volte" è per definizione un
  duplicato da collassare per questo aggregate. Un futuro aggregate (es.
  sotto `Settlement`) i cui comandi ripetuti sono legittimamente azioni
  distinte avrebbe bisogno di una propria strategia di identità, non di
  questa per default.

**Da rivedere se:** viene introdotto un aggregate per cui "stesso contenuto
due volte" non è un duplicato da collassare, o se l'inintuibilità dell'ID
diventa un requisito reale.
