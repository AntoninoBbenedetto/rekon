# ADR-005: Formato CSV custom come unico formato di ingestion per la v1

Stato: Accettato
Data: 2026-08-01

## Contesto

I formati reali di estratti conto bancari/PSP (PagoPA XML, MT940, ISO
20022) sono verbosi, molto specifici e rappresentano in gran parte un
problema di parsing/mapping una volta compresi — non esercitano le parti
del sistema che questo progetto vuole dimostrare (idempotenza, matching,
macchina a stati, audit). Supportarli aggiunge una superficie di parsing
specifica per formato significativa senza aggiungere profondità di design.

## Decisione

L'ingestion v1 accetta un unico formato CSV custom, definito da questo
progetto, contenente esattamente i campi di cui il motore di matching ha
bisogno (vedi l'[addendum di design tecnico](../superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design_it.md)
per lo schema esatto). I formati reali sono esplicitamente fuori scope per
la v1.

## Conseguenze

**Positive:**
- Tutto lo sforzo di design sull'ingestion va nelle parti che contano per
  questo progetto: idempotenza per riga, recupero da import parziale,
  validazione al confine del sistema — non su edge case di parsing
  specifici del formato.
- La forma interna di "riga di estratto conto" prodotta dal parser CSV di
  `Reconciliation` è disaccoppiata da qualunque formato sorgente, quindi
  aggiungere un parser reale in futuro è additivo: un nuovo adapter che
  produce la stessa forma di riga interna, non un redesign.

**Negative / trade-off accettati:**
- Il sistema non può importare un estratto conto bancario reale così
  com'è. Questo è un progetto portfolio, non un'integrazione deployabile —
  accettabile, e dichiarato esplicitamente invece che implicito.

**Da rivedere se:** l'obiettivo del progetto passa da dimostrativo a
integrazione con un feed PSP/banca reale — a quel punto, aggiungere un
parser specifico per formato che si adatti alla forma di riga interna
esistente, come un altro adapter di Infrastructure dentro
`Reconciliation`, non come un nuovo modulo.
