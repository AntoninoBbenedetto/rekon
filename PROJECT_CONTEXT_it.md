# Motore di Riconciliazione Finanziaria
## Contesto del Progetto per l'AI

Questo documento fornisce il contesto architetturale per gli assistenti AI
(ChatGPT, Claude, Gemini, Cursor, GitHub Copilot) che contribuiscono a
questo repository.

L'AI deve sempre dare priorità alla coerenza architetturale rispetto alla
generazione rapida di codice.

Per gli umani, si parte da [README_it.md](README_it.md). Per il design
normativo, vedi
[docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
e [docs/adr/](docs/adr/) — dove questo documento e uno spec/ADR sono in
disaccordo, vince lo spec/ADR e questo documento va corretto.

---

# Visione del Progetto

Il progetto è un motore di riconciliazione finanziaria di livello
enterprise, progettato per ambienti transazionali dove coerenza,
tracciabilità (auditability) e idempotenza sono requisiti obbligatori.

L'obiettivo non è semplicemente importare estratti conto di pagamento.

L'obiettivo è garantire la correttezza finanziaria anche in presenza di
fallimenti.

Gli ambienti target includono:

- FinTech
- Banking
- Piattaforme SaaS
- Pubblica Amministrazione
- Integrazioni PagoPA
- Integrazioni PSP

Il rilevamento delle frodi **non** fa parte di questo sistema — vedi lo spec
della core slice §2.

---

# Principi Ingegneristici

Ogni implementazione deve rispettare questi principi.

## 1. Idempotenza prima di tutto

Ogni comando deve poter essere eseguito in sicurezza più volte.

Eseguire la stessa riconciliazione due volte deve produrre esattamente lo
stesso stato.

Non dare mai per scontato che un comando venga eseguito una sola volta.

Il meccanismo: l'id di aggregate di una `Transaction` è derivato in modo
deterministico dal suo contenuto
([ADR-006](docs/adr/ADR-006-deterministic-aggregate-id_it.md)), a partire dai
campi elencati nell'[ADR-007](docs/adr/ADR-007-idempotency-key-composition_it.md).

---

## 2. Fallimento prima di tutto

Progettare sempre partendo dal presupposto che i fallimenti accadano.

Esempi:

- webhook duplicato
- timeout di rete
- deadlock del database
- import parziale
- retry della coda
- estratto conto bancario duplicato
- esecuzione concorrente

Il sistema deve recuperare automaticamente ogni volta che è possibile.

Ognuno di questi ha un proprio documento in [docs/failures/](docs/failures/),
che dichiara se è mitigato in v1 e con quale meccanismo specifico.

---

## 3. Tracciabilità (Auditability)

Ogni azione importante deve essere tracciabile.

Domande a cui il sistema deve sempre saper rispondere:

- Chi ha eseguito l'azione?
- Quando?
- Perché?
- Da dove?
- Stato precedente
- Nuovo stato

I record di audit non devono mai essere modificati.

È una proprietà strutturale, non una convenzione di logging: lo stato è
derivato da uno stream di eventi append-only, quindi la storia non può essere
sovrascritta. Da notare il limite attuale sul "chi?": la v1 non ha
autenticazione, quindi l'attore registrato è auto-dichiarato
([ADR-008](docs/adr/ADR-008-no-authentication-in-v1_it.md)).

---

## 4. Stato esplicito

Evitare i flag booleani.

Preferire macchine a stati (State Machine) esplicite.

Le transizioni illegali non devono essere possibili: ogni metodo comando
verifica lo stato corrente prima di registrare un evento.

La macchina a stati di `Transaction` è definita in un solo posto — lo
[spec della core slice](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md) §5.
Non ripeterla altrove: gli stati sono già divergiti una volta tra documenti.
`Settled` e `Archived` sono fuori scope per la v1.

---

## 5. Domain Driven Design

Le regole di business appartengono al Dominio.

Evitare di collocare la logica di business dentro:

- Controller
- Job
- Comandi

I Controller orchestrano.

I modelli di Dominio decidono.

---

## 6. Alta coesione

Ogni modulo possiede la propria logica di business.

I moduli devono comunicare tramite interfacce ed eventi di dominio.

Evitare dipendenze tra moduli.

---

# Stile Architetturale

Architettura attuale:

Monolite Modulare

Moduli:

- SharedKernel (infrastruttura di aggregate/eventi, value object — nessuna
  regola di business)
- Reconciliation (acquisizione estratto conto + matching + review manuale
  — un unico bounded context, vedi ADR-001 e ADR-006)
- Settlement (futuro)
- Notification (futuro)

La tracciabilità è una proprietà trasversale dell'event store, non un modulo
a sé.

Ogni modulo possiede:

- Domain (Dominio)
- Application (Applicazione)
- Infrastructure (Infrastruttura)

---

# Stack Tecnico Attuale

Linguaggio

PHP 8.3+

Framework

Laravel 13

Database

PostgreSQL

Coda

Redis

Interfaccia

Solo REST API — nessun pannello di amministrazione, e in particolare niente
Filament ([ADR-004](docs/adr/ADR-004-rest-api-only-no-admin-panel_it.md))

Testing

Pest

---

# Requisiti Non Funzionali

I seguenti sono più importanti del numero di funzionalità.

Ordine di priorità:

1. Correttezza
2. Coerenza
3. Idempotenza
4. Tracciabilità (Auditability)
5. Manutenibilità
6. Performance

Non sacrificare mai la correttezza per la performance.

---

# Linee Guida di Codifica

Preferire

Classi piccole

DTO immutabili

Constructor Injection

Value Object

Enum

Domain Service

Repository solo quando necessario

Evitare

Controller "grassi" (Fat Controller)

Servizi "dio" (God Service)

Stato statico

Effetti collaterali nascosti

Logica di business duplicata

---

# Strategia Transazionale

Le operazioni finanziarie devono essere transazionali.

Usare:

- Transazioni di Database
- Chiavi di Idempotenza
- Vincoli di Unicità
- Politiche di Retry

La concorrenza è gestita con rilevamento ottimistico dei conflitti, non
tenendo lock attraverso la logica di business
([ADR-003](docs/adr/ADR-003-optimistic-concurrency_it.md)).

Evitare movimenti di denaro eventually consistent.

---

# Gestione degli Errori

I fallimenti di business attesi non sono eccezioni.

Usare Result Object o Domain Exception solo per situazioni eccezionali.

Ogni fallimento deve contenere informazioni sufficienti ai fini di audit.

---

# Sicurezza

Non fidarsi mai dei payload esterni.

Validare:

- Importo (Amount)
- Valuta (Currency)
- ID Transazione
- Origine (Source)
- Firma (Signature)
- Hash

Le informazioni sensibili non devono mai essere loggate.

La v1 non ha alcuna autenticazione né autorizzazione — una decisione
deliberata, documentata ed esplicitamente non sicura per la produzione
([ADR-008](docs/adr/ADR-008-no-authentication-in-v1_it.md)).

---

# Regole di Contribuzione per l'AI

Quando genera codice, l'AI deve:

Spiegare sempre le decisioni architetturali.

Preferire la manutenibilità al codice "furbo".

Non introdurre dipendenze non necessarie.

Rispettare i confini esistenti tra i moduli.

Se un cambiamento viola i principi DDD, spiegarne il motivo.

Quando esistono più soluzioni, presentare i trade-off.

---

# Obiettivi del Repository

Questo repository ha lo scopo di dimostrare competenze di ingegneria del
software enterprise.

L'obiettivo è mostrare:

- System Design
- Domain Modeling
- Failure Recovery (recupero dai fallimenti)
- Coerenza Finanziaria
- Clean Architecture
- Integrità Transazionale
- Elaborazione Idempotente
- Tracciabilità (Auditability)

La qualità del codice è preferita alla quantità di funzionalità.
