# Failure: Consegna webhook duplicata

Stato in v1: **Non applicabile ancora — scenario futuro**

## Scenario

Una futura integrazione PSP/PagoPA invia una notifica webhook (es.
"pagamento ricevuto") e, secondo le garanzie tipiche dei PSP, può
consegnare lo stesso webhook più di una volta (la consegna at-least-once è
la norma per queste integrazioni, non l'eccezione).

## Perché è elencato qui adesso

`PROJECT_CONTEXT.md` elenca "webhook duplicato" come failure mode che il
sistema deve gestire in generale. È documentato qui, prima ancora
dell'integrazione che lo scatenerebbe, in modo che il vincolo di design sia
registrato prima che l'ingestion webhook reale venga costruita — non
scoperto dopo.

**La v1 non ha ingestion via webhook.** L'import degli estratti conto in v1
è un file CSV sottomesso direttamente da un chiamante attendibile
([ADR-005](../adr/ADR-005-csv-only-ingestion-v1_it.md)), non una
notifica push da un sistema esterno. Non confondere questo con
[duplicated-statement_it.md](duplicated-statement_it.md), che copre il
meccanismo che la v1 ha realmente.

## Mitigazione prevista (quando l'ingestion via webhook verrà progettata)

Lo stesso meccanismo di idempotenza già dimostrato per le righe CSV
dovrebbe estendersi direttamente: derivare un `IdempotencyKey`
deterministico dai campi identificativi del payload del webhook (es. ID
transazione PSP + tipo di evento), derivare il `TransactionId` da quella
chiave (UUIDv5 —
[ADR-006](../adr/ADR-006-deterministic-aggregate-id_it.md)), e far sì che
una riconsegna con chiave già processata collida sullo stesso aggregate e
venga trattata come no-op — esattamente il pattern in
[duplicated-statement_it.md](duplicated-statement_it.md). L'aggregate
event-sourced `Transaction` non deve cambiare; serve solo un nuovo adapter
(receiver del webhook) dentro il layer Infrastructure del modulo
`Reconciliation`, coerentemente con la nota di
[ADR-005](../adr/ADR-005-csv-only-ingestion-v1_it.md) secondo cui i nuovi
formati sorgente sono adapter additivi, non redesign.

## Verifica

Nessuna ancora — non esiste codice per questo scenario. Aggiungere test di
idempotenza speculari a quelli delle righe CSV (spec della core slice §10)
quando l'ingestion via webhook verrà progettata.
