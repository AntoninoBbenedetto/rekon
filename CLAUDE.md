# CLAUDE.md

Guida operativa per Claude Code in questo repo. Per il "perché" delle scelte
architetturali, leggi prima [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) — è
scritto apposta per assistenti AI. In caso di conflitto tra questo file,
`PROJECT_CONTEXT.md` e la documentazione normativa, vince sempre
[docs/superpowers/specs/](docs/superpowers/specs/) e [docs/adr/](docs/adr/):
correggi il documento che ha drift, non il codice.

## Comandi

```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan test      # oppure: composer test (dentro il container)
docker compose exec app ./vendor/bin/pint      # style fix — nessuno script composer dedicato, nessuna config custom
docker compose exec app ./vendor/bin/phpstan analyse   # static analysis (Larastan, level 6, config in phpstan.neon)
```

Non esiste CI (`.github/workflows` assente): prima di considerare un
cambiamento pronto, esegui manualmente test, Pint e Larastan (nessuno di
questi gira automaticamente).

## Mappa del repo

- `app/Modules/SharedKernel/{Domain,Application,Infrastructure}` — infrastruttura di event sourcing riusabile: `AggregateRoot`, `DomainEvent`, `EventStore` (interfaccia) + `PostgresEventStore`, value object (`Money`, `Currency`, `Actor`, `IdempotencyKey`, `TransactionId`). Nessuna business rule qui.
- `app/Modules/Reconciliation/{Domain,Application,Infrastructure}` — l'unico modulo di business implementato: aggregate `Transaction` (state machine event-sourced), servizi applicativi (`ImportStatementService`, `MatchTransactionService`, `ResolveReviewService`), parser/validator CSV, projector verso il read model, controller HTTP.
- `routes/api.php` — 4 endpoint REST, tutti sotto Reconciliation, id vincolati a UUID.
- `config/reconciliation.php` — namespace UUID usato per derivare gli aggregate id (ADR-006). **Non modificarlo mai**: cambiarlo rompe la corrispondenza tra idempotency key e id già scritti nello store.
- `database/migrations/` — tabelle `event_store` (append-only, fonte di verità), `transactions_read_model` (proiezione disponibile, ricostruibile), `expected_payments`.
- `tests/{Unit,Feature}/Modules/{SharedKernel,Reconciliation}/` — rispecchia 1:1 la struttura di `app/`. I test feature usano `RefreshDatabase` e Pest; i test end-to-end colpiscono le route reali via `postJson`/`getJson`.
- `docs/adr/`, `docs/failures/`, `docs/architecture/`, `docs/superpowers/specs/` — decisioni, modalità di guasto e spec normative. Ogni doc esiste in EN e in IT (suffisso `_it`): se tocchi uno di questi file, aggiorna anche la controparte nell'altra lingua.
- `docs/api/openapi.yaml` — contratto OpenAPI dei 4 endpoint REST, scritto e mantenuto a mano (nessuna generazione automatica): se cambi una request/response nei controller di `Reconciliation/Infrastructure/Http`, aggiorna anche questo file.

## Convenzioni da rispettare quando scrivi codice qui

- Struttura sempre in tre layer per modulo: Domain (regole, niente framework) → Application (orchestrazione, niente regole di business) → Infrastructure (Eloquent, HTTP, code). I controller restano sottili: caricano/salvano tramite repository o `EventStore`, non decidono.
- `Transaction` e le altre classi di dominio sono `final`; ogni transizione di stato passa da `assertState()` prima di registrare un evento — non introdurre transizioni implicite o flag booleani al posto dello stato esplicito.
- Gli eventi si costruiscono con named arguments (vedi `Transaction.php`) e si applicano solo dentro `apply()`/i metodi `applyXxx` privati — mai mutare lo stato dell'aggregate altrove.
- DTO immutabili (`readonly`) per i dati che attraversano i layer (es. `ImportStatementRow`, `ImportSummary`).
- Constructor injection ovunque; niente facade/static state nel Domain.
- Il repository (`TransactionRepository`) calcola `expectedVersion` da `version() - count($events)` per l'optimistic concurrency (ADR-003): se aggiungi un nuovo punto di scrittura, riusa questo pattern, non inventarne un altro.
- Il modulo `SharedKernel` non deve mai dipendere da `Reconciliation` (la dipendenza va in un verso solo).

## Cose da sapere prima di modificare

- v1 non ha autenticazione: l'attore è preso da un header `X-Actor-Id` non validato (default `'unknown'`), scelta deliberata e documentata in ADR-008, non un bug da "sistemare" senza discuterne prima.
- Gli stati `Settled` e `Archived` sono esplicitamente fuori scope v1 (vedi spec core slice §5/§11) — non aggiungerli senza controllare prima la spec.
- Nessun file in `app/` dichiara `declare(strict_types=1)`; se ne aggiungi uno nuovo, valuta se allinearti alla convenzione esistente o introdurre lo strict typing (non è ancora uno standard del progetto).
