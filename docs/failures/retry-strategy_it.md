# Failure: Retry della coda / esecuzione duplicata del job

Stato in v1: **Mitigato**

## Scenario

Le code basate su Redis (e i sistemi di code in generale) offrono consegna
at-least-once, non exactly-once. Un matching job può essere consegnato ed
eseguito più di una volta per la stessa `Transaction` — perché un worker è
crashato dopo l'elaborazione ma prima dell'acknowledgement, per un
requeue dovuto a visibility timeout, o per un retry innescato da un
operatore.

## Perché è importante

Se la logica del matching job assumesse "verrò eseguito solo una volta per
transazione", una riesecuzione potrebbe ri-derivare e riappendere eventi
per una transazione già `Reconciled`, `Rejected`, o comunque oltre il
punto in cui il matching si applica — corrompendo la macchina a stati o
producendo eventi di audit duplicati.

## Mitigazione

Il matching job verifica lo **stato corrente** della transazione (caricato
ripercorrendo il suo stream di eventi) prima di agire. Se la transazione
non è più `Pending` — già `Matched`, `Reconciled`, `Unmatched`,
`NeedsReview`, `Rejected` — il job è un no-op. È lo stesso principio di
"Stato Esplicito" ([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §4) che
previene in generale le transizioni illegali: un job riconsegnato che tenta
una transizione illegale da uno stato diverso da `Pending` viene rifiutato
dalle guardie dell'aggregate stesso, non da logica speciale legata alla
coda.

Combinato con la [concorrenza ottimistica](../adr/ADR-003-optimistic-concurrency_it.md):
anche nella stretta finestra di race in cui due consegne dello stesso job
sono *entrambe* in corso contro una transazione `Pending`, solo un append
può vincere; l'altro riceve un conflitto di versione e, al retry, vede che
la transazione non è più `Pending` e fa no-op.

## Cosa NON è coperto in v1

- La gestione dei "poison message" (un job che fallisce deterministicamente
  ogni volta ed esaurisce i retry) non ha ancora un design dedicato di
  dead-letter — si applica il comportamento di retry/backoff di default del
  queue worker.
- Nessuna chiave di idempotenza a livello di *job* (es. deduplicare payload
  di job in coda identici prima dell'esecuzione) — la deduplicazione avviene
  a livello di dominio/stato invece, il che è sufficiente perché il
  controllo di dominio è autoritativo a prescindere da quante volte il job
  gira.

## Verifica

Coperto da "Queue retry (duplicate job execution)" nello
[spec della core slice](../superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
§8, e dovrebbe avere un test Pest corrispondente: riprocessare una
transazione già `Reconciled` tramite il matching job non produce nuovi
eventi.
