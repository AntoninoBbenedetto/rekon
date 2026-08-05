# Failure: Deadlock del database

Stato in v1: **Mitigato per design (evitato, non gestito)**

## Scenario

Due processi concorrenti agiscono su dati sovrapposti in un ordine che fa
sì che PostgreSQL rilevi un ciclo di lock e ne abortisca uno —
classicamente, il processo A tiene un lock che B vuole e viceversa. In
questo sistema, fonti plausibili di contesa includono: un matching job
rimesso in coda e una risoluzione di review manuale che toccano la stessa
`Transaction` nello stesso momento, oppure due richieste di import
concorrenti che toccano righe sovrapposte.

## Perché è importante

Un deadlock aborta una transazione a metà. Se la transazione abortita
aveva applicato parzialmente cambi di stato finanziario prima del
rollback, o se l'applicazione non distingue "abortita, sicura da
ritentare" da "fallita davvero", questo può produrre stato incoerente o
aggiornamenti persi — una violazione diretta di "Non sacrificare mai la
correttezza per la performance" (`PROJECT_CONTEXT.md`, Requisiti Non
Funzionali).

## Mitigazione

Il design non cerca di *gestire* elegantemente i deadlock — ne evita la
precondizione. Secondo [ADR-003](../adr/ADR-003-optimistic-concurrency_it.md),
nessun percorso di codice tiene un lock pessimista (`SELECT ... FOR
UPDATE`) attraverso l'esecuzione della logica di business. Gli scrittori
concorrenti sulla stessa `Transaction` sono arbitrati dal vincolo di
unicità dell'event store su `(aggregate_id, version)`: chi perde una race
ottiene una violazione di vincolo di unicità, veloce e ben compresa — non
un'attesa di lock che può ciclizzare in un deadlock.

Questo rispecchia il Principio 4 in
[ARCHITECTURE_PRINCIPLES_it.md](../ARCHITECTURE_PRINCIPLES_it.md): la
concorrenza si gestisce rifiutando i conflitti, non con i lock.

## Cosa NON è coperto in v1

- I deadlock che nascono fuori dal percorso di scrittura dell'aggregate
  `Transaction` (es. migrazioni di schema non correlate, query generate
  dall'ORM che toccano più tabelle in un ordine inatteso) non sono protetti
  in modo specifico. Il monitoraggio/alerting generale dei deadlock di
  PostgreSQL è infrastruttura, non design di dominio, e fuori scope qui.

## Verifica

Coperto indirettamente dai "Concurrency tests" nello
[spec della core slice](../superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
§10: simulare un conflitto di versione sull'append e verificare il
comportamento di retry/conflitto — a dimostrazione che il percorso di
conflitto è un rifiuto pulito, non un'attesa di lock.
