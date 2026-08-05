# Glossario

Termini di dominio e tecnici così come usati nella documentazione di
questo repository. Dove un termine ha un significato più ampio altrove,
questo è il significato che ha *qui*.

## Termini di dominio

**Transaction**
Una singola riga di estratto conto bancario, tracciata come aggregate
event-sourced attraverso la propria macchina a stati
(`Pending → Matched/Unmatched/NeedsReview → Reconciled/Rejected`).
Vedi [architecture/overview_it.md](architecture/overview_it.md).

**Expected Payment**
Un record di un pagamento che il sistema si aspetta di ricevere
(riferimento, importo), usato come pool di candidati con cui il motore di
matching confronta le Transaction importate. In v1, dati seed/fixture — non
un modulo gestito (spec della core slice §2).

**Riconciliazione (Reconciliation)**
Il processo complessivo di conferma che una Transaction importata
corrisponda a un pagamento reale atteso, che termina nello stato
`Reconciled`. Da non confondere con "Matching" (un passo dentro la
riconciliazione) o "Settlement" (un modulo separato, fuori scope, che
riguarda il movimento di denaro).

**Matching**
Il passo specifico di confrontare una Transaction `Pending` con i
candidati Expected Payment per importo e riferimento, producendo
esattamente un esito: abbinata (auto-confermata), non abbinata (nessun
candidato), o ambigua (candidati multipli o parziali).

**Manual Review (Revisione Manuale)**
Il passo con intervento umano per le transazioni `NeedsReview`: un
chiamante API sceglie un candidato (→ `Reconciled`) o rifiuta la
transazione (→ `Rejected`).

## Termini di Event Sourcing

**Aggregate**
Un oggetto di dominio il cui stato è derivato interamente ripercorrendo il
proprio stream di eventi, e i cui metodi comando sono l'unico modo per
produrre nuovi eventi per esso. `Transaction` è l'unico aggregate in v1.

**Aggregate Root**
La classe base (`AggregateRoot` in `SharedKernel`) che fornisce la
meccanica comune di event sourcing — stream in memoria, versione,
`apply()`/`record()` — che un aggregate concreto come `Transaction` estende.

**Domain Event**
Un fatto immutabile su qualcosa che è accaduto a un aggregate (es.
`TransactionImported`). Porta con sé `occurredAt`, `actor`, `causationId`,
`correlationId` oltre al proprio payload di business. Vedi
[architecture/c4-component_it.md](architecture/c4-component_it.md).

**Event Store**
Il layer di persistenza append-only per gli eventi di dominio, con chiave
`(aggregate_id, version)` e un vincolo di unicità usato per la concorrenza
ottimistica. L'unica fonte di verità per lo stato dell'aggregate — vedi
[ARCHITECTURE_PRINCIPLES_it.md](ARCHITECTURE_PRINCIPLES_it.md) Principio 3.

**Read Model**
Una proiezione denormalizzata e interrogabile dell'event store, costruita
puramente per letture veloci. Usa e getta — può essere eliminata e
ricostruita rifacendo il replay degli eventi. Mai la fonte di verità.

**Replay**
Ricostruire lo stato corrente di un aggregate ripiegando (fold) il suo
intero stream di eventi dall'inizio. È anche il meccanismo per ricostruire
un read model da zero.

**Concorrenza Ottimistica (Optimistic Concurrency)**
Strategia di rilevamento dei conflitti in cui una scrittura è condizionata
a una versione attesa invece che a un lock tenuto; una mancata
corrispondenza viene rifiutata e il chiamante ritenta. Vedi
[ADR-003](adr/ADR-003-optimistic-concurrency_it.md).

**Idempotency Key (Chiave di Idempotenza)**
Un hash deterministico di un contenuto (es. una riga CSV), usato per
rilevare se quel contenuto è già stato elaborato, rendendo la
rielaborazione un no-op sicuro. Per una riga di estratto conto hasha
`reference + amount_minor_units + currency + statement_date +
occurrence_index` — vedi l'[ADR-007](adr/ADR-007-idempotency-key-composition_it.md)
per il perché di ogni campo incluso o escluso, e
[failures/duplicated-statement_it.md](failures/duplicated-statement_it.md) per
il failure mode che esiste a prevenire.

**occurrence_index**
La posizione di una riga fra le righe dello *stesso estratto conto* identiche
su ogni altro campo hashato. È ciò che impedisce a due pagamenti realmente
identici — stesso importo, stesso giorno, stesso riferimento — di collassare in
un unico aggregate: con ID deterministici (ADR-006), contenuti identici
avrebbero altrimenti identità identica.

**causationId / correlationId**
Due identificatori portati da ogni evento di dominio. `causationId`
traccia quale comando o evento specifico ha causato direttamente questo
evento; `correlationId` traccia a quale processo di business più ampio
(es. "importazione dell'estratto conto X") appartiene questo evento.
Distinti tra loro: la causazione è un puntatore diretto, la correlazione è
una chiave di raggruppamento condivisa lungo tutto un flusso.

## Value Object

**Money**
Importo in unità minime intere + `Currency`. Mai rappresentato come float,
in nessun punto del dominio — l'aritmetica in virgola mobile sul denaro è
una classe di bug di correttezza che questo progetto evita esplicitamente.

**TransactionId**
Identificatore fortemente tipizzato per un aggregate `Transaction`, usato
al posto di una stringa/intero grezzo per evitare di confondere
identificatori tra tipi di aggregate diversi. Derivato in modo
deterministico dalla `IdempotencyKey` dell'aggregate (UUIDv5), non
generato casualmente — vedi
[ADR-006](adr/ADR-006-deterministic-aggregate-id_it.md).

## Termini architetturali

**Modular Monolith (Monolite Modulare)**
Applicazione singola deployabile, organizzata internamente in moduli con
confini imposti (che comunicano tramite eventi/interfacce, non chiamate
dirette agli interni di un altro modulo), a differenza sia di un codebase
unico non suddiviso sia di microservizi fisicamente separati. Vedi
[ADR-001](adr/ADR-001-modular-monolith_it.md).

**Bounded Context** (termine DDD, usato liberamente qui)
L'area di proprietà di un modulo e il proprio linguaggio ubiquo — es. la
nozione di "candidato" (un possibile abbinamento per una `Transaction`) di
`Reconciliation` non trapela nella nozione di payout di `Settlement`.
`Reconciliation` è un unico bounded context, non due: import e matching
condividono lo stesso aggregate `Transaction` e lo stesso ubiquitous
language, per questo sono Application Service dentro un unico modulo
invece di moduli separati — vedi
[ADR-001](adr/ADR-001-modular-monolith_it.md).

**Actor** (come in `DomainEvent.actor`)
Un value object che distingue *chi* ha causato un evento: il `System`
(es. un matching job automatico) rispetto a un chiamante API
identificato. Parte di ciò che rende la tracciabilità
(`PROJECT_CONTEXT.md` §3) rispondibile direttamente dallo stream di
eventi.
