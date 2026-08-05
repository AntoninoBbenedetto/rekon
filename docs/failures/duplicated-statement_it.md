# Failure: Estratto conto duplicato

Stato in v1: **Mitigato**

## Scenario

Lo stesso estratto conto CSV (o un estratto conto contenente righe già
importate in un file precedente) viene sottomesso di nuovo — perché un
chiamante ha ritentato dopo un timeout senza sapere che la prima richiesta
era andata a buon fine, per un ricaricamento manuale, o perché un sistema
a monte rinvia lo stesso file.

## Perché è importante

Senza protezione, reimportare un estratto conto creerebbe aggregate
`Transaction` duplicati per lo stesso movimento bancario sottostante, che
verrebbero poi abbinati e riconciliati due volte — un doppio conteggio di
movimenti di denaro reali. È esattamente la classe di bug che "Idempotenza
prima di tutto" ([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §1) esiste
per prevenire.

## Mitigazione

L'`IdempotencyKey` di ogni riga CSV è un hash deterministico di
`reference + amount_minor_units + currency + statement_date + occurrence_index`
— la composizione esatta, e il perché di ogni campo incluso o escluso, sono
nell'[ADR-007](../adr/ADR-007-idempotency-key-composition_it.md). In
particolare l'identità del file *non* ne fa parte: è questo che fa sì che un
ri-upload sotto un nome file diverso derivi la stessa chiave e non una nuova.
Il `TransactionId` della riga è a sua volta derivato in modo deterministico da
quella `IdempotencyKey` (UUIDv5) invece che generato casualmente — vedi
[ADR-006](../adr/ADR-006-deterministic-aggregate-id_it.md).

Non esiste un passo separato di "verifica se è già stato importato". Il
servizio di import tenta sempre di creare l'aggregate e di fare append di
`TransactionImported` alla expected version 0. Poiché lo stesso contenuto
deriva sempre lo stesso `TransactionId`, questo è imposto direttamente a
livello di storage: il vincolo di unicità dell'event store su
`(aggregate_id, version)` rifiuta una seconda `version = 1` per quello
stesso aggregate. Risottomettere una riga già importata — o un duplicato
realmente concorrente in race con questa — incontra quel conflitto e viene
trattato come no-op, non come errore. Chi perde una race ottiene sempre un
conflitto, mai un duplicato (vedi
[ADR-003](../adr/ADR-003-optimistic-concurrency_it.md)), perché la race è
sempre sullo stesso `aggregate_id`, non su due generati casualmente e
diversi tra loro.

## Cosa NON è coperto in v1

- Due righe che rappresentano lo stesso movimento reale ma con contenuto
  che genera un hash diverso (es. un estratto conto riesportato con un
  formato di timestamp diverso) non vengono rilevate come duplicati —
  l'`IdempotencyKey` è basata sul contenuto, non semantica. È un limite
  noto, non un bug: risolverlo richiede un design di fuzzy-matching fuori
  scope per la v1.
- **Riesportazioni parziali o riordinate.** La deduplicazione è
  per-statement, perché `occurrence_index` (ADR-007) è definito rispetto al
  file in cui la riga arriva. Risottomettere lo *stesso* file è sempre un
  no-op; risottomettere un file che contiene un sottoinsieme riordinato di
  righe già importate può assegnare un indice diverso allo stesso pagamento
  reale e importarlo di nuovo. La garanzia qui è "lo stesso estratto conto è
  sicuro da risottomettere", non "qualunque file che si sovrappone a dati già
  importati è sicuro da risottomettere".

## Il fallimento opposto: due pagamenti realmente identici

Un estratto conto può legittimamente contenere due righe identiche in ogni
campo — due pagamenti reali di pari importo, stesso giorno, stesso
riferimento. L'errore pericoloso sarebbe collassarle: sotto-conta denaro
reale e, con ID di aggregate deterministici, non è riparabile a posteriori,
perché al secondo pagamento non resta alcuna identità distinta da assegnare.

Per questo `occurrence_index` fa parte della chiave
([ADR-007](../adr/ADR-007-idempotency-key-composition_it.md)): la prima di
quelle righe ottiene indice `0`, la seconda indice `1`, quindi derivano chiavi
diverse, `TransactionId` diversi e due aggregate indipendenti — mentre una
risottomissione dell'intero estratto conto riproduce esattamente entrambi gli
indici e resta un no-op.

## Verifica

Coperto dagli "Idempotency tests" nello [spec della core slice](../superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
§10: importare la stessa riga due volte deve produrre esattamente un
evento `TransactionImported`. La stessa suite deve coprire anche la direzione
opposta — un estratto conto contenente due righe identiche produce **due**
aggregate, e risottomettere quello stesso estratto conto non ne produce un
terzo.
