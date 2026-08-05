# ADR-007: Composizione della IdempotencyKey

Stato: Accettato
Data: 2026-08-03

## Contesto

L'[ADR-006](ADR-006-deterministic-aggregate-id_it.md) ha stabilito che
l'identità di una `Transaction` è derivata dal suo contenuto:

```
TransactionId = UUIDv5(APP_NAMESPACE, IdempotencyKey)
```

Il vincolo `UNIQUE (aggregate_id, version)` dell'event store diventa così
l'unico arbitro della race sugli import duplicati: chi perde la race ottiene
una violazione su `version = 1` e viene trattato come no-op.

Quel meccanismo vale però quanto vale il suo input. **Quali campi compongano la
`IdempotencyKey` non era mai stato deciso.** La lacuna era portante, non
cosmetica:

- [`failures/duplicated-statement_it.md`](../failures/duplicated-statement_it.md)
  rimandava all'addendum di technical design "per i campi esatti sottoposti ad
  hash";
- l'addendum non li specificava mai — mostrava un campo `idempotency_key` e un
  secondo campo, mai spiegato, `raw_row_checksum`, nel payload di
  `TransactionImported`.

Il rimando era circolare: la garanzia su cui poggia tutto il resto del sistema
non aveva alcuna definizione dietro di sé. Dalla scelta discendono due
conseguenze, che tirano in direzioni opposte:

- **Troppo dentro la chiave** (per esempio un identificatore di file o il
  timestamp di upload): un ri-upload manuale dello stesso estratto conto deriva
  chiavi *diverse*, quindi aggregate ID diversi, quindi duplicati — proprio lo
  scenario che `duplicated-statement_it.md` dichiara mitigato.
- **Troppo poco dentro la chiave** (per esempio omettendo `statement_date`):
  due pagamenti realmente distinti che si somigliano collassano in un unico
  aggregate. Sotto-contare denaro reale è la peggiore delle due direzioni di
  fallimento e, con ID deterministici, non è recuperabile a valle — il secondo
  pagamento non è *rappresentabile*.

## Decisione

```
IdempotencyKey = sha256(
    reference,
    amount_minor_units,
    currency,
    statement_date,
    occurrence_index
)
```

`occurrence_index` è la posizione (base zero) della riga fra le righe dello
stesso estratto conto identiche sugli altri quattro campi: `0` per la prima
occorrenza, `1` per la seconda, e così via. Le righe non duplicate all'interno
dell'estratto conto hanno sempre `occurrence_index = 0`.

I valori dei campi vengono normalizzati prima dell'hash (trim, `currency` in
maiuscolo, `statement_date` in ISO-8601 `YYYY-MM-DD`, `amount_minor_units` come
stringa intera decimale), così che differenze cosmetiche di formattazione nel
file sorgente non cambino la chiave.

**L'identità del file — nome, id di upload, id di richiesta, timestamp di
caricamento — è deliberatamente esclusa dalla chiave.**

### `raw_row_checksum` è un'altra cosa, e resta

`raw_row_checksum` è lo SHA-256 della riga CSV grezza e non normalizzata, così
come ricevuta. È **prova forense, non identità**: registra ciò che il file
sorgente diceva letteralmente, perché una contestazione futura su una
transazione riconciliata possa essere risolta contro i byte originali anche
dopo che le regole di normalizzazione del parser sono cambiate. Viaggia nel
payload di `TransactionImported` e non viene mai usato per deduplicazione,
matching o derivazione dell'ID.

## Conseguenze

**Positive:**
- **Il ri-upload resta idempotente.** Lo stesso estratto conto sottomesso due
  volte — con un nome file diverso, dopo un timeout, o da un chiamante diverso
  — deriva le stesse chiavi, gli stessi aggregate ID, e collassa sul vincolo di
  unicità dell'event store esattamente come descrive ADR-006.
- **Le righe legittimamente identiche restano distinte.** Due pagamenti reali
  di pari importo, nella stessa data, con lo stesso riferimento ottengono
  `occurrence_index = 0` e `1`, quindi due aggregate. Senza questo campo il
  design sotto-conterebbe denaro in silenzio e — essendo l'identità
  deterministica — non offrirebbe alcun modo di ripararlo dopo.
- **Il rimando rotto è chiuso.** `duplicated-statement_it.md` e l'addendum di
  technical design puntano entrambi qui, e qui c'è una definizione.

**Negative / trade-off accettati:**
- **Il parser non è più stateless riga per riga.** Calcolare
  `occurrence_index` richiede di sapere cosa è arrivato prima nello stesso
  estratto conto: le righe non possono essere hashate isolatamente mentre
  scorrono. Vanno raggruppate per gli altri quattro campi, all'interno
  dell'estratto conto, prima di derivare le chiavi.
- **L'idempotenza è per-statement, non globale.** L'`occurrence_index` di una
  riga è definito rispetto all'estratto conto in cui arriva. Un file contenente
  un *sottoinsieme riordinato* di righe già importate — per esempio una
  riesportazione che ne omette alcune — può assegnare un indice diverso allo
  stesso pagamento reale, e quella riga verrà importata di nuovo come nuovo
  aggregate. Risottomettere lo **stesso** file è sempre sicuro; risottomettere
  un'esportazione **parziale o riordinata** di dati sovrapposti no. È un limite
  noto, messo a verbale qui invece di essere scoperto dopo.
- **Cambiare questa definizione in futuro cambia l'identità degli aggregate.**
  Per ADR-006 il `TransactionId` derivato è accoppiato a questa composizione;
  rivederla richiederebbe una migrazione dei dati già importati. Non è un
  problema per la v1 (non ce ne sono), ma rende questo ADR costoso da
  invertire.
- Due righe che descrivono lo stesso pagamento reale ma differiscono su un
  qualsiasi campo hashato (per esempio una riesportazione che rende il
  riferimento in modo diverso) continuano a non essere riconosciute come
  duplicati. La chiave è basata sul contenuto, non sulla semantica — limite già
  registrato in `duplicated-statement_it.md` e qui invariato.

**Da rivedere se:** gli estratti conto iniziano ad arrivare come esportazioni
parziali/sovrapposte invece che come file interi, cioè il caso che
`occurrence_index` non copre — la soluzione sarebbe un controllo semantico
(fuzzy) sui duplicati, un design diverso, non un hash diverso.
