# Failure: Timeout di rete

Stato in v1: **Mitigato**

## Scenario

Un chiamante sottomette un import CSV (o una risoluzione di review) e la
connessione va in timeout prima che arrivi la risposta — la richiesta può
essere stata elaborata completamente lato server oppure no, e il chiamante
non può saperlo dal solo timeout.

## Perché è importante

La reazione naturale di un client a un timeout è "ritentare". Se ritentare
un'operazione parzialmente o completamente completata non è sicuro, un
timeout di rete si trasforma in un bug di elaborazione duplicata — lo
stesso problema di fondo di
[duplicated-statement_it.md](duplicated-statement_it.md), innescato da una
causa diversa (condizioni di rete invece di una risottomissione
deliberata).

## Mitigazione

Poiché ogni scrittura in questo sistema è idempotente per design — import
di riga CSV con chiave `IdempotencyKey`
([duplicated-statement_it.md](duplicated-statement_it.md)), e comandi
dell'aggregate protetti da asserzioni sullo stato atteso
([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §4) — una richiesta andata
in timeout è sempre sicura da ritentare alla cieca, senza che il chiamante
debba sapere se la richiesta originale sia arrivata a destinazione.
"Ritentare al timeout" non richiede alcuna logica speciale in nessun punto
di questo sistema; discende dal fatto che l'idempotenza è il default, non
un'eccezione.

L'elaborazione per riga (spec della core slice §6, §8) fa sì che un
timeout a metà di un file CSV multi-riga richieda solo di risottomettere
lo stesso file — le righe già elaborate sono no-op, le righe non ancora
elaborate vengono elaborate normalmente.

## Cosa NON è coperto in v1

- La politica di retry lato client (backoff, tentativi massimi) è
  responsabilità del chiamante, non qualcosa che il server impone o
  documenta qui.
- Nessuna chiave di idempotenza a livello di richiesta HTTP/cache della
  risposta (es. rigiocare la risposta HTTP originale esatta per una
  richiesta ritentata) — i retry sono sicuri perché sono no-op a livello di
  dominio, non perché l'API mette in cache le risposte.

## Verifica

Nessun test dedicato oltre a quanto già coperto dai test di idempotenza e
import parziale (spec della core slice §10) — questa failure mode è una
conseguenza di quelle garanzie, non un meccanismo separato da testare in
isolamento.
