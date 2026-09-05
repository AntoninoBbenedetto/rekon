# ADR-009: Outbox transazionale per le scritture cross-sistema

Stato: Accettato
Data: 2026-09-05

## Contesto

`ImportStatementService::import()` esegue tre scritture su due sistemi
diversi per ogni riga importata:

1. `TransactionRepository::save($transaction)` → `event_store` (Postgres,
   atomico al suo interno grazie al `DB::transaction` che avvolge gli
   insert in `PostgresEventStore::append()`).
2. `TransactionReadModelProjector::project($transaction)` →
   `transactions_read_model` (Postgres, query separata, fuori da
   qualunque transazione).
3. `MatchPendingTransactionJob::dispatch(...)` → coda Redis.

Solo la prima scrittura è transazionale. Il processo può morire tra due
qualsiasi di questi passi — deploy, OOM killer, `SIGKILL`, crash del
worker — lasciando il sistema in uno stato che nulla ripara:

- **Crash dopo 1:** l'evento `TransactionImported` esiste nell'event
  store, ma il read model non ha la riga corrispondente.
  `GET /transactions/{id}` restituisce 404 su una transazione che esiste
  realmente. Niente lo ripara, perché non esiste un comando di rebuild
  della proiezione — il che significa anche che l'affermazione del README
  secondo cui "the queryable read model is a disposable projection of that
  store, never a source of truth" non è ancora vera nel codice: una
  proiezione è disposable solo se sai davvero ricostruirla.
- **Crash dopo 2:** la transazione è `Pending` sia nell'event store che
  nel read model, ma il job di matching non è mai arrivato su Redis.
  Nessuno la matcherà mai. Resta `Pending` per sempre, silenziosamente —
  un incasso non riconciliato, esattamente il failure mode che questo
  progetto esiste per prevenire (`PROJECT_CONTEXT.md` §2, "Financial
  Consistency").

Due fix apparentemente ovvi non chiudono il problema:

- Avvolgere i passi 1–2 in un unico `DB::transaction` chiude la prima
  finestra (entrambe le scritture sono su Postgres, quindi committano
  insieme), ma non fa nulla per la seconda: Redis non partecipa a una
  transazione Postgres. Se il commit riesce e il dispatch fallisce, la
  transazione resta comunque bloccata `Pending`. Se il dispatch riesce ma
  il commit fallisce, è peggio — il worker gira contro un aggregate mai
  persistito.
- `DB::afterCommit()` sposta il dispatch dopo il commit, eliminando il
  caso "job senza dati", ma non "dati senza job": il processo può ancora
  morire tra il commit e l'esecuzione dell'hook. Questo restringe la
  finestra, non la chiude — e in un sistema la cui intera premessa è la
  riconciliazione idempotente garantita, quella differenza conta.

Il problema generale: due scritture su due sistemi diversi non possono
essere rese atomiche senza un commit distribuito, e un commit distribuito
(XA, two-phase commit) è una cura peggiore della malattia per un monolite
con una singola coda Redis.

`MatchPendingTransactionJob::handle()` ricontrolla già lo stato della
transazione prima di agire ed è un no-op (a parte la ri-proiezione) se non
è più `Pending`. Questa idempotenza già esistente è la precondizione che
rende sicuro introdurre un meccanismo di consegna at-least-once.

## Decisione

Adottare il pattern **outbox transazionale**: dato che due sistemi non
possono essere scritti atomicamente, scrivere solo su Postgres — inclusa
l'*intenzione* di scrivere su Redis — nella stessa transazione delle
scritture di dominio, e lasciare che un processo separato e disaccoppiato
trasformi quell'intenzione nel dispatch reale.

Concretamente:

- Una nuova tabella `outbox` (`message_type`, `payload` jsonb,
  `correlation_id`, `created_at`) vive interamente dentro il modulo
  `Reconciliation`
  (`app/Modules/Reconciliation/Infrastructure/Outbox/OutboxWriter.php`),
  non in `SharedKernel`: `Reconciliation` è oggi l'unico consumatore, e il
  relay che legge questa tabella conosce necessariamente
  `MatchPendingTransactionJob`, che è specifico di business —
  `SharedKernel` non deve dipenderne.
- `ImportStatementService::import()` avvolge `repository->save()`,
  `projector->project()` e `outbox->publish(...)` in un'unica
  `DB::transaction()`. O tutte e tre le righe esistono, o nessuna — non
  c'è più uno stato intermedio da cui il sistema non sappia uscire da
  solo. Lo stesso avvolgimento viene applicato alla coppia `save()` +
  `project()` di `MatchPendingTransactionJob::handle()`, per lo stesso
  motivo (evento e proiezione devono committare insieme), anche se quel
  percorso non ha bisogno di una riga outbox perché non dispatcha nulla a
  valle.
- Un comando artisan di relay, `reconciliation:relay-outbox`, legge righe
  outbox (`SELECT ... FOR UPDATE SKIP LOCKED` per permettere più relay
  concorrenti), dispatcha il job reale e cancella la riga. Se il relay
  muore tra dispatch e delete, la riga sopravvive e viene ripubblicata al
  giro successivo. Questa è consegna **at-least-once** — l'unica garanzia
  ottenibile senza un commit distribuito — ed è sicura proprio perché
  `MatchPendingTransactionJob` è già idempotente.
- `reconciliation:rebuild-projection` tronca `transactions_read_model` e
  la riproduce da `event_store`, rendendo vera l'affermazione sulla
  "disposable projection" già presente nel README.

**Perché una tabella `outbox` separata e non l'`event_store` stesso come
log ordinato con un checkpoint `last_event_id`:** `event_store.id` è un
bigserial Postgres, e in Postgres il valore di una sequence viene
assegnato *prima* del commit. Due transazioni concorrenti possono
prendere gli id 100 e 101; se la 101 committa per prima, un relay che
legge in quel momento vede 101, avanza il proprio checkpoint, e non vedrà
mai la 100 quando questa committa un istante dopo — un evento perso
silenziosamente, sotto carico, esattamente quando fa più male. Si può
gestire (leggere solo eventi più vecchi di N secondi, tracciare
`pg_snapshot_xmin`, logical decoding), ma sono tutte complicazioni
aggiuntive. Una tabella separata con cancellazione dopo la pubblicazione
non ha ordinamento da preservare: leggi le righe visibili, le pubblichi,
le cancelli. Una riga invisibile in questo giro sarà semplicemente
visibile al giro successivo.

## Conseguenze

**Positive:**
- Non esiste più uno stato intermedio da cui il sistema non possa uscire
  da solo: event store, read model e intenzione outbox committano insieme
  o per niente.
- Il read model diventa una proiezione davvero ricostruibile, in linea con
  l'affermazione del README invece di contraddirla.
- Nessuna nuova infrastruttura: niente Kafka, niente Debezium, niente
  CDC — solo una tabella, un comando di relay e un comando di rebuild, che
  riusano il consumer idempotente già esistente.
- Il formato della riga outbox (`message_type`/`payload`/`correlation_id`)
  è riusabile se in futuro serve un secondo tipo di messaggio o un secondo
  modulo, senza essere stato costruito come componente generico
  cross-modulo prima che esista un secondo consumatore a giustificarlo.

**Negative / trade-off accettati:**
- La consegna at-least-once significa che `MatchPendingTransactionJob` può
  essere eseguito più di una volta per la stessa transazione. Già sicuro
  oggi perché il job ricontrolla lo stato prima di agire, ma qualunque
  futuro consumer dell'outbox dovrà mantenere la stessa invariante di
  idempotenza.
- Il relay è un processo in più da tenere vivo e monitorare (scheduler o
  loop); se si ferma, l'outbox cresce silenziosamente a meno che qualcosa
  non ne osservi l'età — mitigato con un log di warning quando la riga più
  vecchia non processata supera una soglia, non con una nuova
  infrastruttura di metriche.
- C'è una piccola latenza aggiuntiva tra "riga committata" e "job
  effettivamente in coda su Redis", rispetto al precedente (non sicuro)
  dispatch sincrono.

**Da rivedere se:** un secondo modulo ha bisogno di consegna in stile
outbox (promuovere tabella e writer a `SharedKernel`, mantenendo la
logica di dispatch specifica del modulo fuori da esso), o il volume di
messaggi cresce al punto da giustificare un consumer basato su CDC.
