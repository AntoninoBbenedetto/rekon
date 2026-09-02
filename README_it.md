# Motore di Riconciliazione Finanziaria

![CI](https://github.com/AntoninoBbenedetto/rekon/actions/workflows/ci.yml/badge.svg)

*[English version](README.md)*

Uno studio di design su come tenere corretto lo stato finanziario in presenza
di fallimenti: importare estratti conto bancari, abbinarli ai pagamenti attesi
e riconciliarli — dove ogni scrittura è idempotente, ogni conflitto viene
rilevato invece che chiuso dentro un lock, e la storia completa di una
transazione *è* l'audit trail.

Il dominio è la riconciliazione dei pagamenti (di forma PagoPA/PSP). Il tema è
l'ingegneria: idempotenza, macchine a stati esplicite, sicurezza sotto
concorrenza e tracciabilità come proprietà strutturali, non come convenzioni.

## Stato

**Implementato.** Lo slice verticale v1 descritto di seguito è costruito e
testato — import CSV, matching, risoluzione manuale delle revisioni e API
REST sono tutti presenti, con 95 test superati che coprono percorsi unit,
integration ed end-to-end. Il repository contiene anche l'architettura che
lo ha guidato: spec, ADR, diagrammi C4 e un'analisi dei failure mode.

L'ordine è deliberato. Il design ha già dovuto correggersi una volta:
l'[ADR-006](docs/adr/ADR-006-deterministic-aggregate-id_it.md) esiste perché un
documento precedente affermava una garanzia di concorrenza che il meccanismo,
così come progettato, non forniva davvero. Scoprirlo sulla carta è costato un
documento; scoprirlo in produzione sarebbe costato movimenti di denaro
duplicati.

Stack previsto: PHP 8.3+, Laravel 13, PostgreSQL, Redis, Pest. Solo REST API —
nessun pannello di amministrazione.

## Cosa fa il sistema (slice v1)

```
estratto conto CSV → chiave di idempotenza per riga → aggregate Transaction
                                                       (event-sourced)
                                                              ↓
                              matching contro i pagamenti attesi
                                                              ↓
          Reconciled  |  Unmatched  |  NeedsReview → Reconciled / Rejected
```

Ogni transizione di stato è un evento di dominio appeso a un event store; il
read model interrogabile è una proiezione usa e getta di quello store, mai una
fonte di verità.

## Per iniziare

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Per eseguire la suite di test:

```bash
docker compose exec app php artisan test
```

## Ordine di lettura

Si parte da qui per il *perché*:

1. [docs/ARCHITECTURE_PRINCIPLES_it.md](docs/ARCHITECTURE_PRINCIPLES_it.md) —
   le convinzioni di fondo dietro le decisioni (coerenza prima della latenza,
   conflitti invece di lock, event sourcing come strumento e non come stile
   della casa).
2. [docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md)
   — il design normativo della v1: scope, macchina a stati, flusso end-to-end,
   strategia di testing.
3. [docs/adr/](docs/adr/) — otto decisioni, ciascuna con le alternative
   considerate e il trade-off accettato.
4. [docs/failures/](docs/failures/) — un documento per ogni failure mode
   atteso, che dichiara se la v1 lo mitiga e con quale meccanismo specifico.

Materiale di riferimento:

- [docs/architecture/overview_it.md](docs/architecture/overview_it.md) e i
  diagrammi C4 ([contesto](docs/architecture/c4-context_it.md),
  [container](docs/architecture/c4-container_it.md),
  [componenti](docs/architecture/c4-component_it.md)).
- [docs/superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design_it.md](docs/superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design_it.md)
  — schema DB, payload degli eventi, contratti API.
- [docs/glossary_it.md](docs/glossary_it.md) — il vocabolario, nel significato
  che ha *qui*.
- [PROJECT_CONTEXT_it.md](PROJECT_CONTEXT_it.md) — lo stesso contesto scritto
  per gli assistenti AI di codifica.

## Decisioni su cui vale la pena saltare direttamente

| | |
|---|---|
| [ADR-001](docs/adr/ADR-001-modular-monolith_it.md) | Monolite modulare, non microservizi |
| [ADR-002](docs/adr/ADR-002-hand-rolled-event-sourcing_it.md) | Event sourcing scritto a mano — e perché in produzione sarebbe la scelta sbagliata |
| [ADR-003](docs/adr/ADR-003-optimistic-concurrency_it.md) | Concorrenza ottimistica invece dei lock pessimisti |
| [ADR-006](docs/adr/ADR-006-deterministic-aggregate-id_it.md) | Identità dell'aggregate derivata dal contenuto, che elimina una race verifica-poi-agisci |
| [ADR-007](docs/adr/ADR-007-idempotency-key-composition_it.md) | Cosa viene hashato esattamente — incluso perché due pagamenti identici devono restare due |
| [ADR-008](docs/adr/ADR-008-no-authentication-in-v1_it.md) | Nessuna autenticazione in v1, e il rischio che ciò accetta |

## Fuori scope

I moduli Settlement e Notification, gli stati `Settled`/`Archived`, i formati
reali di estratto conto (PagoPA XML, MT940), i pagamenti attesi come modulo
gestito, l'autenticazione e il rilevamento delle frodi in qualsiasi forma.
Ciascuno è elencato con la propria motivazione nello spec della core slice §2 e
§11 — qui le omissioni sono decisioni, non dimenticanze.

## La documentazione è bilingue

Ogni documento esiste in inglese e in italiano; la versione italiana ha il
suffisso `_it` (es. `docs/glossary_it.md`). Le due sono tenute allineate: una
modifica a una delle due è incompleta finché l'altra non corrisponde.
