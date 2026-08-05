# ADR-003: Concorrenza ottimistica invece di locking pessimista

Stato: Accettato
Data: 2026-08-01

## Contesto

Più processi possono agire concorrentemente sullo stesso aggregate
`Transaction`: un import CSV risottomesso, un matching job rimesso in coda
(redelivery per retry della coda), e una risoluzione di review manuale
potrebbero in linea di principio competere. `PROJECT_CONTEXT.md` elenca
"deadlock del database" ed "esecuzione concorrente" come failure mode
attese che il sistema deve gestire, non evitare per ipotesi.

Esistono due approcci standard: locking pessimista (`SELECT ... FOR UPDATE`
o equivalente, tenuto per la durata dell'operazione) o concorrenza
ottimistica (rilevare le scritture in conflitto a posteriori, rifiutarle e
lasciare che il chiamante ritenti).

## Decisione

Usare **controllo di concorrenza ottimistica** sullo stream di eventi
dell'aggregate `Transaction`. Ogni append di evento è condizionato a una
`expected_version`; la tabella `event_store` impone un vincolo di unicità
su `(aggregate_id, version)`. Un append in conflitto fallisce e il
chiamante (matching job, handler della richiesta API) è responsabile di
ritentare contro la nuova versione corrente.

Non tenere lock pessimisti a lunga durata attraverso l'esecuzione della
logica di business.

## Conseguenze

**Positive:**
- Affronta direttamente la failure mode di deadlock di `PROJECT_CONTEXT.md`:
  non c'è nessun lock tenuto attraverso codice applicativo dove potrebbe
  formarsi un deadlock.
- I conflitti sono economici da rilevare (una violazione di vincolo di
  unicità) e la loro risoluzione (retry) è uniforme su ogni comando
  dell'aggregate.
- Si adatta naturalmente al modello event-sourced — la "versione attesa" è
  già il modo in cui il dominio ragiona su "cosa è successo finora".

**Negative / trade-off accettati:**
- Sotto alta contesa su un singolo aggregate, i retry potrebbero
  accumulare latenza. Non è una preoccupazione ai volumi di dati di questo
  progetto; andrebbe rivisto (es. serializzando le scritture per aggregate
  tramite una coda) se la contesa diventasse reale.
- Ogni percorso di scrittura deve essere retry-aware; un chiamante che
  ignora una risposta di conflitto e non ritenta perde silenziosamente
  un'operazione legittima. Mitigato rendendo il conflitto una risposta
  esplicita e tipizzata (non un 500 generico), così da non poter essere
  scambiata per un errore non correlato.

**Da rivedere se:** il profiling sotto carico concorrente realistico mostra
tempeste di retry su aggregate "caldi".
