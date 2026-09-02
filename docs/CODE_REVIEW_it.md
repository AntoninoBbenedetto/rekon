# Code review: lo slice v1 generato da AI

*[English version](CODE_REVIEW.md)*

Gran parte dello slice verticale v1 — l'aggregate `Transaction`, i servizi
applicativi, l'event store, i controller, la suite di test — è stata
generata da un assistente AI a partire dalla [spec della core slice](superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
e da [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md). Questo è dichiarato qui
deliberatamente: il codice generato è una bozza, non un deliverable. La
spec guida la generazione; la review decide cosa viene effettivamente
consegnato. Questo documento è quella review — cosa ha trovato, perché
ogni rilievo conta in questo dominio specifico, e cosa è cambiato di
conseguenza.

## Cosa la review ha accettato

Il codice generato ha centrato le parti difficili, ed è giusto dirlo prima
di elencare cosa non ha centrato:

- `Transaction::assertState()` viene chiamato prima di ogni evento
  registrato — le transizioni di stato illegali sono strutturalmente
  impossibili, non solo evitate per convenzione ([Transaction.php](../app/Modules/Reconciliation/Domain/Transaction.php)).
- `TransactionRepository::save()` calcola correttamente `expectedVersion`,
  e `PostgresEventStore::append()` lascia risalire una violazione di
  vincolo unico fuori da `DB::transaction()` senza intercettarla — una
  scrittura multi-evento parziale fa rollback completo, non un commit a metà.
- `ImportStatementService` riproietta la transazione esistente in caso di
  conflitto invece di fallire — comportamento idempotente effettivamente
  esercitato da `EndToEndReconciliationTest`, non solo dichiarato.
- `MatchPendingTransactionJob` verificava già lo stato corrente prima di
  questa passata — un job riconsegnato era già sicuro da rieseguire.
  Semplicemente, non veniva rieseguito.

Due rilievi sotto sono stati giudicati meritevoli di correzione prima che
questo slice venga presentato come lavoro di portfolio.

## Rilievo 1: nessun `declare(strict_types=1)` in `app/`

**Cosa c'era.** Tutti i 52 file in `app/` giravano nella modalità di
tipizzazione debole di default di PHP. `Money::__construct(int $amountMinorUnits, ...)`
e `StoredEventRow::__construct(..., int $version, ...)` dichiarano
parametri `int`, ma la modalità debole coercizza silenziosamente:
`new Money('12345', $currency)` è legale, e una stringa `'123'` che
arrivasse vicino a un parametro `int` tipizzato verrebbe accettata invece
che rifiutata.

**Perché conta qui specificamente, non genericamente.** Questo è un
registro finanziario dove gli importi sono minor unit intere e "non
sacrificare mai la correttezza per le performance" è la priorità non
funzionale #1 dichiarata esplicitamente
([PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md), Non Functional Requirements).
Il rischio concreto era il confine in
[`PostgresEventStore::loadStream()`](../app/Modules/SharedKernel/Infrastructure/EventStore/PostgresEventStore.php):
legge `$row->version` direttamente da una riga risultato PDO dentro il
parametro `int $version` di `StoredEventRow`. Il tipo di ritorno di PDO
per le colonne intere dipende dalla configurazione del driver
(`PDO::ATTR_EMULATE_PREPARES`); una stringa che filtrasse da lì sarebbe
stata, in modalità debole, coercizzata silenziosamente e confrontata come
stringa solo se fosse mai sfuggita dal costruttore non convertita. La
tipizzazione debole non stava *causando* un bug — stava eliminando la
rete di sicurezza che ne avrebbe intercettato uno se la configurazione PDO
di questo ambiente, o il driver del DB, fossero mai cambiati sotto al
codice.

**Cosa è successo davvero applicando il fix.** `declare(strict_types=1);`
è stato aggiunto a tutti i 52 file in `app/` (i file di test, database e
config sono stati deliberatamente lasciati fuori scope). L'intera suite è
stata eseguita prima e dopo: **95 test passati prima, 95 passati dopo —
zero rotture.** Invece di lasciarlo come un'assunzione, il confine PDO è
stato ispezionato direttamente:

```
bigint -> integer
int    -> integer
emulate_prepares -> false
```

Questo ambiente restituisce già interi PHP nativi per le colonne intere di
Postgres, perché `ATTR_EMULATE_PREPARES` è `false`. Quindi il rischio
specifico al confine non era attivo *in questa configurazione*. La
conclusione onesta non è "è stato corretto un bug" — è che **un invariante
su cui il codice faceva affidamento silenziosamente (tipi interi nativi da
PDO) è ora garantito dal sistema di tipi invece che da un'assunzione non
dichiarata sulla configurazione del driver.** Se quell'impostazione PDO, o
il driver, dovessero mai cambiare, questo ora fallisce rumorosamente con
un `TypeError` esattamente nel punto di chiamata invece di coercizzare una
stringa dentro un'operazione aritmetica più a valle. È questo il valore
che la tipizzazione stretta aggiunge in un codebase come questo: non bug
intercettati oggi, ma una superficie di fallimento più ristretta domani.

## Rilievo 2: `MatchPendingTransactionJob` non aveva una retry policy

**Cosa c'era.** Il job implementa `ShouldQueue` ma non dichiarava
`$tries`, `$backoff`, `failed()`. Lo script `dev` di [`composer.json`](../composer.json)
lancia il queue worker con `--tries=1`. `docs/failures/retry-strategy.md`
era marcato **Mitigato**, ma la mitigazione che descrive effettivamente è
la guardia di idempotenza del matching job contro la *consegna
at-least-once* — non dice nulla su cosa succede quando il job fallisce del
tutto.

**La distinzione che conta.** Sono due proprietà diverse, e il codice
generato ne aveva solo una:

- **Sicuro da rieseguire** — un job riconsegnato che trova la transazione
  non più `Pending` fa no-op invece di corrompere lo stato. Questo esisteva
  già.
- **Effettivamente rieseguito** — qualcosa deve davvero schedulare il
  retry. Questo non esisteva. Con `--tries=1` e nessuno sweep di alcun
  tipo (`app/Console/Commands` è vuoto; nulla nel codebase cerca
  `replay`/`rebuild`/`reproject`), un fallimento transitorio — una
  connessione DB caduta, un intoppo di Redis, un deploy a metà — lasciava
  la transazione **`Pending` per sempre, silenziosamente.** Nessun errore
  emerge da nessuna parte visibile a un umano.

**Il fix.** `MatchPendingTransactionJob` ora dichiara:

```php
public int $tries = 5;
public array $backoff = [5, 15, 60, 180];
```

Il backoff cresce invece di restare fisso per una ragione specifica di
questo sistema, non per best practice generica: per l'[ADR-003](adr/ADR-003-optimistic-concurrency_it.md),
chi perde un conflitto di concorrenza sullo stesso aggregate deve dare al
vincitore il tempo di completare il commit. Riprovare immediatamente
significherebbe solo tornare a contendersi la stessa riga e perdere di
nuovo — un ritardo fisso e breve renderebbe i retry autolesionisti proprio
nello scenario per cui esistono.

È stato aggiunto un metodo `failed()` per chiudere il gap di visibilità
una volta esauriti i retry:

```php
public function failed(?Throwable $exception): void
{
    Log::error('Matching permanently failed; transaction left Pending.', [
        'transaction_id' => $this->transactionId,
        'correlation_id' => $this->correlationId,
        'exception' => $exception?->getMessage(),
    ]);
}
```

Vengono loggati solo gli identificatori — nessun contenuto della
transazione — come da sezione Security di `PROJECT_CONTEXT.md`. Sono stati
aggiunti due test in
[`MatchPendingTransactionJobTest.php`](../tests/Feature/Modules/Reconciliation/MatchPendingTransactionJobTest.php):
uno verifica la configurazione retry/backoff, l'altro verifica che
`failed()` logghi gli identificatori giusti con il messaggio giusto. Il
test su `failed()` è stato verificato per mutazione a mano — modificare la
stringa del messaggio loggato ha confermato di rompere l'asserzione —
quindi si sa che vincola davvero il comportamento, non che si limita a
eseguirlo. Suite completa dopo entrambe le modifiche: **97 test passati**
(95 di baseline + 2 nuovi).

**Cosa questo fix non chiude.** Una transazione che esaurisce tutti e 5 i
retry è ora *visibile* (in `failed_jobs` e nel log applicativo) ma ancora
**non recuperata** — nulla ri-processa automaticamente una transazione
`Pending` rimasta bloccata. `docs/failures/retry-strategy.md` ora lo
dichiara esplicitamente invece di lasciare intendere che la mitigazione
sia completa. Trasformare la visibilità in recupero — un comando
schedulato che trova le transazioni `Pending` bloccate oltre una certa età
e le rimette in coda — è lavoro futuro, nominato qui invece che lasciato
implicito.

## Cosa questa passata non ha toccato

Identificato anche durante l'analisi di qualità più ampia, deliberatamente
lasciato aperto qui invece che infilato in un diff non correlato:

- Nessuna CI (`.github/workflows` assente) — esistono 97 test e nulla li
  esegue al push.
- Nessuna static analysis (PHPStan/Larastan) — plausibile vista la
  quantità di `match(true)` e cast espliciti in questo codebase, che è
  esattamente dove un passaggio a livello di tipi ripaga il proprio costo.
- Pint è una dipendenza ma non è agganciato a uno script `composer`, e il
  codebase attualmente non è Pint-clean sotto il suo preset di default —
  l'enforcement dello stile è opzionale nella pratica, non imposto.
- Nessun comando per ricostruire la proiezione del read model dallo stream
  di eventi, nonostante il read model sia documentato come usa-e-getta e
  ricostruibile.

Due rilievi sono stati corretti in questa passata perché erano i due con
uno scenario di fallimento concreto e argomentabile collegato — non perché
la lista sopra conti meno. È registrata come lavoro futuro, non nascosta
sotto l'affermazione che questa passata abbia reso il codebase completo.
