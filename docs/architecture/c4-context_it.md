# C4 — Contesto di Sistema (v1)

Chi e cosa interagisce con il Motore di Riconciliazione Finanziaria, al
livello di astrazione più alto.

```mermaid
C4Context
    title Contesto di Sistema — Core Slice di Riconciliazione (v1)

    Person(caller, "Chiamante API", "Un client attendibile che sottomette estratti conto e revisiona le transazioni. Nessuna autenticazione in v1 — vedi ADR-004.")

    System(reconciliation, "Motore di Riconciliazione", "Importa estratti conto bancari, abbina le transazioni agli Expected Payment e le riconcilia con un audit trail completo.")

    Person(reviewer, "Revisore", "Una persona che risolve le transazioni NeedsReview tramite l'API — in v1, lo stesso chiamante attendibile, nessun ruolo distinto ancora modellato.")

    Rel(caller, reconciliation, "Sottomette estratti conto CSV, interroga lo stato delle transazioni", "REST/JSON")
    Rel(reviewer, reconciliation, "Risolve le transazioni ambigue", "REST/JSON")
```

## Note

- C'è esattamente un tipo di attore esterno in v1: un chiamante API,
  assunto attendibile (nessuna autenticazione —
  [ADR-004](../adr/ADR-004-rest-api-only-no-admin-panel_it.md)).
  "Revisore" è mostrato separatamente per riflettere un *ruolo* distinto nel
  dominio (risolvere i casi `NeedsReview`), anche se la v1 non lo modella
  come un attore di sistema distinto con proprie credenziali.
- Nessun sistema PSP, banca o PagoPA compare come attore esterno per ora —
  l'ingestion v1 è un file CSV sottomesso dal chiamante, non
  un'integrazione live ([ADR-005](../adr/ADR-005-csv-only-ingestion-v1_it.md)).
  Un sistema PSP/banca reale comparirebbe qui come nuovo attore esterno
  quando quell'integrazione verrà progettata.
- Nessuna controparte di Settlement (es. un rail di payout) compare — fuori
  scope per la v1 (spec della core slice §2).
