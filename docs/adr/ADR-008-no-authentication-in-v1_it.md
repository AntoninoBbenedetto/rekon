# ADR-008: Nessuna autenticazione o autorizzazione in v1

Stato: Accettato
Data: 2026-08-03

## Contesto

L'API REST della v1 (`POST /imports`, `GET /transactions`,
`GET /transactions/{id}`, `POST /transactions/{id}/resolve`) espone ogni
operazione che il sistema possiede: importare estratti conto finanziari,
leggere l'intero audit trail di qualsiasi transazione, e risolvere un caso
`NeedsReview` in un esito `Reconciled` o `Rejected`.

Questa decisione era originariamente registrata come una clausola dentro
l'[ADR-004](ADR-004-rest-api-only-no-admin-panel_it.md), il cui oggetto è "API
REST come unica interfaccia, niente pannello di amministrazione". Questo
rendeva introvabile l'unica decisione che il progetto marca esplicitamente come
**non sicura per la produzione**: nessuno cerca una decisione
sull'autenticazione dentro un ADR sui pannelli di amministrazione. Viene
estratta qui perché sia rintracciabile, e perché riconsiderarla non implichi
riconsiderare anche la decisione sull'interfaccia.

## Decisione

La v1 non prevede **alcuna autenticazione e alcuna autorizzazione**. L'API
assume un unico chiamante fidato. Non esistono credenziali, sessioni, API key,
ruoli, né controlli di permesso per attore.

Il value object `Actor` trasportato da ogni evento di dominio continua a
distinguere `System` da un chiamante API identificato — ma in v1 l'identità del
chiamante è **auto-dichiarata e non verificata**. È metadato di audit, non un
principal autenticato.

## Conseguenze

**Positive:**
- Zero sforzo di interfaccia speso su uno schema di autenticazione che non
  dimostrerebbe nulla di ciò che questo progetto vuole mostrare (il pubblico è
  quello degli ingegneri backend generalisti — vedi la
  [spec del core slice](../superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md) §1).
- I feature test Pest esercitano dominio e API direttamente, senza
  infrastruttura di test per token o sessioni di mezzo.
- Nulla nel design di dominio presuppone un modello di autorizzazione, quindi
  aggiungerne uno in seguito è additivo (middleware + risoluzione reale
  dell'`Actor`), non una riprogettazione.

**Negative / trade-off accettati:**
- **L'API v1 non è collocabile su nessuna rete raggiungibile.** Chiunque riesca
  a raggiungerla può importare estratti conto, leggere la storia completa di
  ogni transazione, e riconciliare o rifiutare transazioni. È dichiarato come
  rischio aperto, non aggirato in silenzio.
- **Il campo `actor` dell'audit trail non è affidabile in v1.** Registra chi il
  chiamante *ha dichiarato* di essere. L'auditabilità (`PROJECT_CONTEXT.md` §3)
  è quindi strutturalmente completa ma probatoriamente debole finché non esiste
  l'autenticazione: lo stream di eventi risponde alla domanda "chi?" solo nella
  misura in cui il chiamante è stato onesto.
- **`Reconciled` e `Rejected` sono azioni finanziariamente rilevanti e non
  autenticate.** In qualunque deployment reale sono esattamente le operazioni
  che richiedono un attore autenticato, autorizzato e attribuibile.
- L'imprevedibilità degli ID non offre alcuna protezione compensativa: per
  l'[ADR-006](ADR-006-deterministic-aggregate-id_it.md) un `TransactionId` è
  derivabile per costruzione dal contenuto della riga e dal namespace UUID.

**Da rivedere se:** il progetto viene deployato oltre un ambiente locale/demo —
a quel punto l'autenticazione è obbligatoria, non opzionale, e l'`Actor` dei
nuovi eventi deve diventare un principal autenticato invece di una stringa
auto-dichiarata. L'introduzione di ruoli distinti (chi importa vs. chi
revisiona), che [c4-context_it.md](../architecture/c4-context_it.md) già modella
come distinzione di dominio senza un confine di sistema dietro, appartiene alla
stessa modifica.
