# Reconciliation Core Slice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the v1 vertical slice of the Financial Reconciliation Engine: import a CSV bank statement, match transactions against seeded expected payments, and reconcile them, with full idempotency, optimistic-concurrency safety, and an immutable event-sourced audit trail — exposed only via a REST API.

**Architecture:** Modular monolith (`SharedKernel`, `Reconciliation`) under `app/Modules`. `Reconciliation` is a single bounded context: import and matching are two Application Services (`ImportStatementService`, `MatchTransactionService`-equivalent job + `ResolveReviewService`) operating on the same event-sourced `Transaction` aggregate — not separate modules. Every state transition is a domain event; a synchronous projector maintains a queryable read model (`transactions_read_model`) from those events in the same request/job that appends them.

**Tech Stack:** PHP 8.3+, Laravel 13, PostgreSQL, Redis (queues), Pest.

## Fonti di verità

Questo piano non ridecide nulla — implementa esattamente:
1. [`docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md`](../specs/2026-08-01-reconciliation-core-slice-design.md) (spec approvata — vince in caso di conflitto)
2. [`docs/superpowers/specs/2026-08-01-reconciliation-core-slice-technical-design.md`](../specs/2026-08-01-reconciliation-core-slice-technical-design.md) (schema DB, payload eventi, contratti API)
3. [`docs/adr/ADR-001`](../../adr/ADR-001-modular-monolith.md)–[`ADR-008`](../../adr/ADR-008-no-authentication-in-v1.md)
4. `PROJECT_CONTEXT.md`, `docs/failures/*.md`

## Global Constraints

- PHP 8.3+, Laravel 13, PostgreSQL, Redis queues, Pest — spec §3.
- No admin panel / no Filament — REST API is the only interface (spec §7, §9; ADR-004).
- No authentication/authorization in v1 — assume a trusted caller (spec §2, §7; ADR-008). Caller identity for `Actor` is self-declared via an optional `X-Actor-Id` header, defaulting to `"unknown"` — not verified.
- Event sourcing is hand-rolled (no `spatie/laravel-event-sourcing` or similar package) — ADR-002.
- Money is always integer minor units + a `Currency` enum — never floats (spec §4).
- `Transaction` aggregate identity is deterministic: `TransactionId = UUIDv5(APP_NAMESPACE, IdempotencyKey)` — ADR-006. Import never checks existence first; it always attempts the append and treats a conflict as a no-op.
- `IdempotencyKey = sha256(reference, amount_minor_units, currency, statement_date, occurrence_index)`, normalized fields, computed per-statement (rows grouped before hashing) — ADR-007.
- Optimistic concurrency only: event append conditioned on `expected_version`; `event_store` has `UNIQUE (aggregate_id, version)`. No pessimistic locks — ADR-003.
- Expected Payments are seed/fixture data (a factory), not a managed module (spec §2).
- Read-model projector runs synchronously, in the same request/job as the event append that triggers it (decision made in brainstorming; both options were spec-compliant per technical design §5).
- CSV row content validation uses Laravel Form Requests plus custom `ValidationRule` classes for `Money`/`Currency` — no new third-party validation library (decision made in brainstorming; technical design §5 left this open).
- Out of scope: Settlement, Notification, `Settled`/`Archived` states, real statement formats (PagoPA/MT940), event store snapshots, authentication, a review UI, fraud detection (spec §11).
- Every task follows TDD: write the failing test first (given/when/then for aggregate tests, per spec §10), watch it fail, implement the minimal code, watch it pass, commit.

## Implementation decisions not fixed by the spec

The technical design (§5) explicitly leaves some choices to this plan. Resolved here so every task can rely on them without re-deciding:

- **CSV `statement_date` format:** ISO-8601 `YYYY-MM-DD`, validated with Laravel's `date_format:Y-m-d` rule.
- **`TransactionId` namespace UUID:** a fixed constant, `fe04f55c-d438-4630-a660-dc8d6afb6672`, stored in `config/reconciliation.php` (`RECONCILIATION_TRANSACTION_ID_NAMESPACE` env var). Never changes after v1 ships — changing it would change every derived `TransactionId` (ADR-006's accepted trade-off).
- **`POST /imports` response — rows invalid by content:** the technical design's example response (§4) covers structurally-valid rows only (`rows_received`, `rows_imported`, `rows_already_imported`, `transaction_ids`) and separately states a row with bad content must not fail the whole request (spec §8), without saying how such rows are reported. This plan adds two fields to that response — `rows_invalid` (count) and `invalid_rows` (`[{row_number, errors}]`) — as the resolution of that gap, not a deviation from the approved contract.
- **`correlation_id` threading:** one `correlation_id` is generated per `POST /imports` call and reused by every event produced by that import *and* by the asynchronous matching job it dispatches for each row — the whole "importing statement X" process shares one correlation id. A `POST /transactions/{id}/resolve` call is a separate, later, human-initiated business process against an already-existing transaction, so it gets its own fresh `correlation_id`. `causation_id` is a fresh UUID per command execution (one import row, one matching job run, one resolve call).
- **Event type strings** (`event_store.event_type`): `transaction.imported`, `transaction.matched`, `transaction.marked_unmatched`, `transaction.marked_ambiguous`, `transaction.reconciled`, `transaction.rejected`.
- **File placement follows the technical design addendum §1 exactly** where it states one: `EventStore` (the interface) lives under `SharedKernel/Application/`, only `PostgresEventStore` (the implementation) is `Infrastructure`; `ExpectedPayment` is a plain Eloquent model under `Reconciliation/Domain/`, per the addendum's own note and ADR-002. The matching decision is one class, `MatchTransactionService` (Application layer), querying `ExpectedPayment` directly — no extra `MatchingEngine`/`ExpectedPaymentFinder`/`MatchOutcome` indirection, matching the addendum's minimal structure.
- **`MatchPendingTransactionJob` naming:** the addendum's file tree (§1) names this `MatchPendingTransactionsJob.php` (plural) but its own prose two paragraphs later says `dispatches one MatchPendingTransactionJob` (singular) — an internal inconsistency in that document. This plan uses the singular form, matching the prose (which describes the one-job-per-transaction behavior the class actually has) and the discarded prior plan's precedent.
- **`ImportStatementRow`:** the addendum lists one DTO by this name under `Application/`. This plan still parses in two stages internally (`StatementLine` — raw, untyped, produced by `CsvStatementParser` in `Infrastructure`; `ImportStatementRow` — validated and normalized, produced by `StatementRowValidator` and consumed by `ImportStatementService`) since content validation cannot happen before the row is at least lexically parsed. Only the validated shape is given the addendum's name.

---

## File Structure

```
app/
  Modules/
    SharedKernel/
      Domain/
        Actor.php
        ActorType.php
        Currency.php
        Money.php
        IdempotencyKey.php
        TransactionId.php
        DomainEvent.php
        AbstractDomainEvent.php
        AggregateRoot.php
        Exceptions/
          ConcurrencyConflictException.php
      Application/
        EventStore.php
      Infrastructure/
        EventStore/
          StoredEventRow.php
          PostgresEventStore.php
    Reconciliation/
      Domain/
        Transaction.php
        TransactionState.php
        ExpectedPayment.php
        Events/
          TransactionImported.php
          TransactionMatched.php
          TransactionMarkedUnmatched.php
          TransactionMarkedAmbiguous.php
          TransactionReconciled.php
          TransactionRejected.php
          TransactionEventTypes.php
        Exceptions/
          IllegalTransactionStateTransition.php
          TransactionNotFound.php
          InvalidResolutionCandidate.php
      Application/
        TransactionRepository.php
        ImportStatementService.php
        ImportStatementRow.php
        MatchTransactionService.php
        ResolveReviewService.php
      Infrastructure/
        CsvStatementParser.php
        StatementLine.php
        MalformedStatementException.php
        StatementRowValidator.php
        Rules/
          ValidMoneyAmountRule.php
          ValidCurrencyRule.php
        Persistence/
          TransactionProjection.php
        TransactionReadModelProjector.php
        MatchPendingTransactionJob.php
        Http/
          Controllers/
            ImportsController.php
            TransactionsController.php
            ResolveTransactionController.php
          Requests/
            ImportStatementRequest.php
            ResolveTransactionRequest.php
  Providers/
    ReconciliationServiceProvider.php

config/
  reconciliation.php

database/
  migrations/
    xxxx_create_event_store_table.php
    xxxx_create_expected_payments_table.php
    xxxx_create_transactions_read_model_table.php
  factories/
    ExpectedPaymentFactory.php

routes/
  api.php

tests/
  Unit/
    Modules/
      SharedKernel/
        MoneyTest.php
        IdempotencyKeyTest.php
        TransactionIdTest.php
      Reconciliation/
        TransactionTest.php
        MatchingEngineTest.php
  Feature/
    Modules/
      SharedKernel/
        PostgresEventStoreTest.php
      Reconciliation/
        CsvStatementParserTest.php
        ImportStatementServiceTest.php
        MatchPendingTransactionJobTest.php
        ResolveReviewServiceTest.php
        ImportsEndpointTest.php
        TransactionsEndpointTest.php
        ResolveTransactionEndpointTest.php
        EndToEndReconciliationTest.php
```

---

## Task 1: Bootstrap del progetto Laravel 13 con Pest e PostgreSQL

**Files:**
- Create: intero scaffold Laravel 13 (`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `tests/`, `artisan`, `composer.json`, `.env.example`)
- Modify: nessuno dei file esistenti (`README.md`, `PROJECT_CONTEXT.md`, `README_it.md`, `PROJECT_CONTEXT_it.md`, `docs/`, `.gitignore`) va sovrascritto

Il repository contiene solo documentazione — non esiste ancora codice applicativo. Lo scaffold Laravel va generato in una directory temporanea e unito alla root, per non perdere i file esistenti.

- [ ] **Step 1: Genera lo scaffold Laravel in una directory temporanea**

```bash
composer create-project laravel/laravel _laravel_scaffold "^13.0" --prefer-dist --no-interaction
```

- [ ] **Step 2: Unisci lo scaffold nella root del repository, senza toccare i file di progetto esistenti**

```bash
rsync -a _laravel_scaffold/ ./ --exclude='.git' --exclude='.gitignore' --exclude='README.md'
rm -rf _laravel_scaffold
```

Verifica: `ls` deve mostrare sia `app/`, `artisan`, `composer.json` sia `README.md`, `PROJECT_CONTEXT.md`, `docs/` invariati. `git status` deve mostrare `README.md` senza modifiche.

- [ ] **Step 3: Installa Pest**

```bash
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
php artisan pest:install
```

Rispondi "yes" se richiesto di rimuovere PHPUnit come test runner di default.

- [ ] **Step 4: Configura la connessione PostgreSQL**

Modifica `.env` e `.env.example`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rekon
DB_USERNAME=rekon
DB_PASSWORD=rekon
```

Crea `.env.testing` (usato automaticamente da Pest/Laravel in ambiente di test):

```
APP_ENV=testing
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rekon_testing
DB_USERNAME=rekon
DB_PASSWORD=rekon
QUEUE_CONNECTION=sync
```

Crea entrambi i database Postgres (`rekon`, `rekon_testing`) se non esistono già:

```bash
createdb rekon
createdb rekon_testing
```

- [ ] **Step 5: Genera l'application key ed esegui la migrazione iniziale di Laravel**

```bash
php artisan key:generate
php artisan migrate
```

Expected: nessun errore di connessione al DB; le tabelle di default di Laravel (`users`, `cache`, `jobs`, ecc.) vengono create su `rekon`.

- [ ] **Step 6: Configura la coda su Redis per l'ambiente di sviluppo**

In `.env`:

```
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

(`.env.testing` resta `QUEUE_CONNECTION=sync`, così i job di matching eseguono in-process durante i test senza bisogno di un worker.)

- [ ] **Step 7: Verifica che la suite Pest scaffoldata passi**

```bash
php artisan test
```

Expected: PASS (i test di default di Laravel, es. `ExampleTest`).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: bootstrap Laravel 13 application with Pest and PostgreSQL"
```

---

## Task 2: SharedKernel — Currency enum e Money value object

**Files:**
- Create: `app/Modules/SharedKernel/Domain/Currency.php`
- Create: `app/Modules/SharedKernel/Domain/Money.php`
- Test: `tests/Unit/Modules/SharedKernel/MoneyTest.php`

**Interfaces:**
- Produces: `Currency` (backed enum: `EUR`, `USD`, `GBP`), `Money::__construct(int $amountMinorUnits, Currency $currency)`, `Money::equals(Money $other): bool`. Used by every later task that touches an amount.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\Money;

it('exposes amount and currency', function () {
    $money = new Money(12345, Currency::EUR);

    expect($money->amountMinorUnits)->toBe(12345)
        ->and($money->currency)->toBe(Currency::EUR);
});

it('rejects a negative amount', function () {
    new Money(-1, Currency::EUR);
})->throws(InvalidArgumentException::class);

it('considers two Money equal when amount and currency match', function () {
    $a = new Money(500, Currency::EUR);
    $b = new Money(500, Currency::EUR);
    $c = new Money(500, Currency::USD);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MoneyTest`
Expected: FAIL — class `Money`/`Currency` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

use InvalidArgumentException;

final class Money
{
    public function __construct(
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
    ) {
        if ($amountMinorUnits < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public function equals(Money $other): bool
    {
        return $this->amountMinorUnits === $other->amountMinorUnits
            && $this->currency === $other->currency;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MoneyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/Currency.php app/Modules/SharedKernel/Domain/Money.php tests/Unit/Modules/SharedKernel/MoneyTest.php
git commit -m "feat(shared-kernel): add Currency enum and Money value object"
```

---

## Task 3: SharedKernel — Actor value object e ActorType enum

**Files:**
- Create: `app/Modules/SharedKernel/Domain/ActorType.php`
- Create: `app/Modules/SharedKernel/Domain/Actor.php`
- Test: `tests/Unit/Modules/SharedKernel/MoneyTest.php` (nessuna modifica — questo task non ha un file di test dedicato: `Actor` è coperto indirettamente dai test del `Transaction` aggregate al Task 12; qui verifichiamo solo il costruttore statico con un test minimo)

**Interfaces:**
- Produces: `ActorType` (backed enum: `System`, `ApiCaller`), `Actor::system(): self`, `Actor::apiCaller(string $id): self`, proprietà pubbliche `$type` e `$id`. Consumato da ogni evento di dominio e da ogni comando del `Transaction` aggregate.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/SharedKernel/ActorTest.php`:

```php
<?php

use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ActorType;

it('creates a system actor with no id', function () {
    $actor = Actor::system();

    expect($actor->type)->toBe(ActorType::System)
        ->and($actor->id)->toBeNull();
});

it('creates an api caller actor with an id', function () {
    $actor = Actor::apiCaller('caller-42');

    expect($actor->type)->toBe(ActorType::ApiCaller)
        ->and($actor->id)->toBe('caller-42');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActorTest`
Expected: FAIL — class `Actor`/`ActorType` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain;

enum ActorType: string
{
    case System = 'system';
    case ApiCaller = 'api_caller';
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

final class Actor
{
    private function __construct(
        public readonly ActorType $type,
        public readonly ?string $id,
    ) {
    }

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function apiCaller(string $id): self
    {
        return new self(ActorType::ApiCaller, $id);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ActorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/ActorType.php app/Modules/SharedKernel/Domain/Actor.php tests/Unit/Modules/SharedKernel/ActorTest.php
git commit -m "feat(shared-kernel): add Actor value object and ActorType enum"
```

---

## Task 4: SharedKernel — IdempotencyKey value object

**Files:**
- Create: `app/Modules/SharedKernel/Domain/IdempotencyKey.php`
- Test: `tests/Unit/Modules/SharedKernel/IdempotencyKeyTest.php`

**Interfaces:**
- Consumes: `Currency` (Task 2).
- Produces: `IdempotencyKey::forStatementRow(string $reference, int $amountMinorUnits, Currency $currency, DateTimeImmutable $statementDate, int $occurrenceIndex): self`, property `$value` (string, sha256 hex digest), `equals(IdempotencyKey $other): bool`. Consumed by `TransactionId::deriveFrom` (Task 5) and `ImportStatementService` (Task 21).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;

it('derives the same key from the same normalized content', function () {
    $a = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $b = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    expect($a->equals($b))->toBeTrue();
});

it('derives a different key when occurrence_index differs', function () {
    $a = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $b = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 1);

    expect($a->equals($b))->toBeFalse();
});

it('derives a different key when any of the other four fields differ', function () {
    $base = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    $differentReference = IdempotencyKey::forStatementRow('REF-2', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $differentAmount = IdempotencyKey::forStatementRow('REF-1', 99999, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $differentCurrency = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::USD, new DateTimeImmutable('2026-07-31'), 0);
    $differentDate = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-08-01'), 0);

    expect($base->equals($differentReference))->toBeFalse()
        ->and($base->equals($differentAmount))->toBeFalse()
        ->and($base->equals($differentCurrency))->toBeFalse()
        ->and($base->equals($differentDate))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=IdempotencyKeyTest`
Expected: FAIL — class `IdempotencyKey` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain;

use DateTimeImmutable;

final class IdempotencyKey
{
    private function __construct(public readonly string $value)
    {
    }

    public static function forStatementRow(
        string $reference,
        int $amountMinorUnits,
        Currency $currency,
        DateTimeImmutable $statementDate,
        int $occurrenceIndex,
    ): self {
        $normalized = implode('|', [
            trim($reference),
            (string) $amountMinorUnits,
            $currency->value,
            $statementDate->format('Y-m-d'),
            (string) $occurrenceIndex,
        ]);

        return new self(hash('sha256', $normalized));
    }

    public function equals(IdempotencyKey $other): bool
    {
        return $this->value === $other->value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=IdempotencyKeyTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/IdempotencyKey.php tests/Unit/Modules/SharedKernel/IdempotencyKeyTest.php
git commit -m "feat(shared-kernel): add IdempotencyKey value object (ADR-007)"
```

---

## Task 5: SharedKernel — TransactionId value object

**Files:**
- Create: `app/Modules/SharedKernel/Domain/TransactionId.php`
- Create: `config/reconciliation.php`
- Test: `tests/Unit/Modules/SharedKernel/TransactionIdTest.php`

**Interfaces:**
- Consumes: `IdempotencyKey` (Task 4).
- Produces: `TransactionId::deriveFrom(IdempotencyKey $key): self`, `TransactionId::fromString(string $value): self`, property `$value` (string UUID), `equals(TransactionId $other): bool`, `__toString(): string`. Consumed by every task touching the `Transaction` aggregate's identity.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\TransactionId;

it('derives the same TransactionId from the same IdempotencyKey', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    $a = TransactionId::deriveFrom($key);
    $b = TransactionId::deriveFrom($key);

    expect($a->equals($b))->toBeTrue()
        ->and($a->value)->toBeString()
        ->and(strlen($a->value))->toBe(36);
});

it('derives different TransactionIds from different IdempotencyKeys', function () {
    $keyA = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $keyB = IdempotencyKey::forStatementRow('REF-2', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    expect(TransactionId::deriveFrom($keyA)->equals(TransactionId::deriveFrom($keyB)))->toBeFalse();
});

it('round-trips through fromString', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    expect(TransactionId::fromString((string) $id)->equals($id))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionIdTest`
Expected: FAIL — class `TransactionId` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

return [
    // Fixed application namespace used to derive deterministic Transaction
    // aggregate ids from their IdempotencyKey (ADR-006). Never change this
    // after v1 ships: doing so would change every derived TransactionId.
    'transaction_id_namespace' => env('RECONCILIATION_TRANSACTION_ID_NAMESPACE', 'fe04f55c-d438-4630-a660-dc8d6afb6672'),
];
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

use Ramsey\Uuid\Uuid;

final class TransactionId
{
    private function __construct(public readonly string $value)
    {
    }

    public static function deriveFrom(IdempotencyKey $key): self
    {
        $namespace = config('reconciliation.transaction_id_namespace');

        return new self(Uuid::uuid5($namespace, $key->value)->toString());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(TransactionId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionIdTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/TransactionId.php config/reconciliation.php tests/Unit/Modules/SharedKernel/TransactionIdTest.php
git commit -m "feat(shared-kernel): add TransactionId value object (ADR-006)"
```

---

## Task 6: SharedKernel — DomainEvent interface, AbstractDomainEvent, StoredEventRow

**Files:**
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/StoredEventRow.php`
- Create: `app/Modules/SharedKernel/Domain/DomainEvent.php`
- Create: `app/Modules/SharedKernel/Domain/AbstractDomainEvent.php`
- Test: `tests/Unit/Modules/SharedKernel/AbstractDomainEventTest.php`

This task has no concrete event to test against yet (those come in Task 11), so the test exercises `AbstractDomainEvent` through a minimal anonymous subclass defined inline in the test file — this is the one place in the plan where a throwaway test double is appropriate, since `AbstractDomainEvent` is never instantiated directly in production code.

**Interfaces:**
- Produces: `DomainEvent` interface (`aggregateId(): string`, `eventType(): string`, `occurredAt(): DateTimeImmutable`, `actor(): Actor`, `causationId(): string`, `correlationId(): string`, `payload(): array`, static `fromStoredRow(StoredEventRow $row): static`), `AbstractDomainEvent` (implements the envelope accessors, leaves `eventType()`, `payload()`, `fromStoredRow()` abstract), `StoredEventRow` (readonly DTO: `$aggregateId`, `$version`, `$eventType`, `$payload` (array), `$occurredAt`, `$actor`, `$causationId`, `$correlationId`). Consumed by every event class in `Reconciliation\Domain\Events` (Task 11) and by `PostgresEventStore` (Task 9).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

final class FakeEvent extends AbstractDomainEvent
{
    public function eventType(): string
    {
        return 'fake.happened';
    }

    public function payload(): array
    {
        return ['note' => 'hello'];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self($row->aggregateId, $row->occurredAt, $row->actor, $row->causationId, $row->correlationId);
    }
}

it('exposes the envelope fields it was constructed with', function () {
    $occurredAt = new DateTimeImmutable('2026-08-01T10:00:00+00:00');
    $actor = Actor::system();

    $event = new FakeEvent('agg-1', $occurredAt, $actor, 'causation-1', 'correlation-1');

    expect($event)->toBeInstanceOf(DomainEvent::class)
        ->and($event->aggregateId())->toBe('agg-1')
        ->and($event->occurredAt())->toBe($occurredAt)
        ->and($event->actor())->toBe($actor)
        ->and($event->causationId())->toBe('causation-1')
        ->and($event->correlationId())->toBe('correlation-1')
        ->and($event->eventType())->toBe('fake.happened')
        ->and($event->payload())->toBe(['note' => 'hello']);
});

it('reconstructs from a StoredEventRow', function () {
    $row = new StoredEventRow(
        aggregateId: 'agg-1',
        version: 1,
        eventType: 'fake.happened',
        payload: ['note' => 'hello'],
        occurredAt: new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        actor: Actor::system(),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );

    $event = FakeEvent::fromStoredRow($row);

    expect($event->aggregateId())->toBe('agg-1');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AbstractDomainEvent`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Domain\Actor;
use DateTimeImmutable;

final class StoredEventRow
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $version,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
        public readonly Actor $actor,
        public readonly string $causationId,
        public readonly string $correlationId,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

interface DomainEvent
{
    public function aggregateId(): string;

    public function eventType(): string;

    public function occurredAt(): DateTimeImmutable;

    public function actor(): Actor;

    public function causationId(): string;

    public function correlationId(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    public static function fromStoredRow(StoredEventRow $row): static;
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

use DateTimeImmutable;

abstract class AbstractDomainEvent implements DomainEvent
{
    public function __construct(
        private readonly string $aggregateId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly Actor $actor,
        private readonly string $causationId,
        private readonly string $correlationId,
    ) {
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    public function causationId(): string
    {
        return $this->causationId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AbstractDomainEvent`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/DomainEvent.php app/Modules/SharedKernel/Domain/AbstractDomainEvent.php app/Modules/SharedKernel/Infrastructure/EventStore/StoredEventRow.php tests/Unit/Modules/SharedKernel/AbstractDomainEventTest.php
git commit -m "feat(shared-kernel): add DomainEvent interface, AbstractDomainEvent, StoredEventRow"
```

---

## Task 7: SharedKernel — AggregateRoot base class

**Files:**
- Create: `app/Modules/SharedKernel/Domain/AggregateRoot.php`
- Test: `tests/Unit/Modules/SharedKernel/AggregateRootTest.php`

Like Task 6, this is tested through a minimal concrete subclass defined inline in the test file, since `AggregateRoot` is abstract and `Transaction` (its real subclass) doesn't exist until Task 12.

**Interfaces:**
- Consumes: `DomainEvent` (Task 6).
- Produces: `AggregateRoot` (abstract): `record(DomainEvent $event): void` (protected), `apply(DomainEvent $event): void` (abstract, protected), `releaseEvents(): array<DomainEvent>`, `version(): int`, `reconstituteFromStream(array<DomainEvent> $events): static`, `createEmpty(): static` (abstract, protected static). Consumed by `Transaction` (Task 12).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

final class FakeIncrementedEvent extends AbstractDomainEvent
{
    public function eventType(): string
    {
        return 'fake.incremented';
    }

    public function payload(): array
    {
        return [];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self($row->aggregateId, $row->occurredAt, $row->actor, $row->causationId, $row->correlationId);
    }
}

final class FakeCounter extends AggregateRoot
{
    private string $id;
    private int $count = 0;

    public static function start(string $id): self
    {
        $counter = self::createEmpty();
        $counter->id = $id;
        $counter->record(new FakeIncrementedEvent($id, new DateTimeImmutable(), Actor::system(), 'c1', 'r1'));

        return $counter;
    }

    public function incrementAgain(): void
    {
        $this->record(new FakeIncrementedEvent($this->id, new DateTimeImmutable(), Actor::system(), 'c2', 'r1'));
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function count(): int
    {
        return $this->count;
    }

    protected function apply(DomainEvent $event): void
    {
        $this->id = $event->aggregateId();
        $this->count++;
    }

    protected static function createEmpty(): static
    {
        return new self();
    }
}

it('applies recorded events immediately and tracks version', function () {
    $counter = FakeCounter::start('agg-1');

    expect($counter->count())->toBe(1)
        ->and($counter->version())->toBe(1);

    $counter->incrementAgain();

    expect($counter->count())->toBe(2)
        ->and($counter->version())->toBe(2);
});

it('releases recorded events exactly once', function () {
    $counter = FakeCounter::start('agg-1');
    $counter->incrementAgain();

    $events = $counter->releaseEvents();

    expect($events)->toHaveCount(2)
        ->and($counter->releaseEvents())->toHaveCount(0);
});

it('reconstitutes state and version by replaying a stream', function () {
    $events = [
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1'),
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c2', 'r1'),
        new FakeIncrementedEvent('agg-1', new DateTimeImmutable(), Actor::system(), 'c3', 'r1'),
    ];

    $counter = FakeCounter::reconstituteFromStream($events);

    expect($counter->count())->toBe(3)
        ->and($counter->version())->toBe(3)
        ->and($counter->releaseEvents())->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AggregateRootTest`
Expected: FAIL — class `AggregateRoot` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain;

abstract class AggregateRoot
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private int $version = 0;

    abstract public function aggregateId(): string;

    protected function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->version++;
        $this->recordedEvents[] = $event;
    }

    abstract protected function apply(DomainEvent $event): void;

    /** @return DomainEvent[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @param DomainEvent[] $events */
    public static function reconstituteFromStream(array $events): static
    {
        $aggregate = static::createEmpty();

        foreach ($events as $event) {
            $aggregate->apply($event);
            $aggregate->version++;
        }

        return $aggregate;
    }

    abstract protected static function createEmpty(): static;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AggregateRootTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/AggregateRoot.php tests/Unit/Modules/SharedKernel/AggregateRootTest.php
git commit -m "feat(shared-kernel): add AggregateRoot base class"
```

---

## Task 8: SharedKernel — ConcurrencyConflictException e migrazione `event_store`

**Files:**
- Create: `app/Modules/SharedKernel/Domain/Exceptions/ConcurrencyConflictException.php`
- Create: `database/migrations/<timestamp>_create_event_store_table.php`
- Test: nessuno (l'eccezione è una struttura dati pura; la migrazione è verificata dai test del Task 9, che scrivono e leggono dalla tabella)

**Interfaces:**
- Produces: `ConcurrencyConflictException` (extends `RuntimeException`; readonly `$aggregateId`, `$attemptedVersion`), tabella `event_store`. Consumato da `PostgresEventStore` (Task 9), `ImportStatementService` (Task 21), `ResolveTransactionController` (Task 27).

- [ ] **Step 1: Write the migration**

```bash
php artisan make:migration create_event_store_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_store', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_id');
            $table->unsignedInteger('version');
            $table->string('event_type');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->uuid('causation_id');
            $table->uuid('correlation_id');
            $table->timestampTz('recorded_at');

            $table->unique(['aggregate_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_store');
    }
};
```

- [ ] **Step 2: Write the exception class**

```php
<?php

namespace App\Modules\SharedKernel\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class ConcurrencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $attemptedVersion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Concurrency conflict on aggregate {$aggregateId} at version {$attemptedVersion}.",
            previous: $previous,
        );
    }
}
```

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: `event_store` table created with no errors, on both `rekon` (dev) and `rekon_testing` (via `php artisan migrate --env=testing` or by letting Pest's `RefreshDatabase` trait run it — configured in Task 9).

- [ ] **Step 4: Commit**

```bash
git add app/Modules/SharedKernel/Domain/Exceptions/ConcurrencyConflictException.php database/migrations/*_create_event_store_table.php
git commit -m "feat(shared-kernel): add ConcurrencyConflictException and event_store migration"
```

---

## Task 9: SharedKernel — EventStore interface e PostgresEventStore

**Files:**
- Create: `app/Modules/SharedKernel/Application/EventStore.php`
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/PostgresEventStore.php`
- Test: `tests/Feature/Modules/SharedKernel/PostgresEventStoreTest.php`

Uses `RefreshDatabase`, quindi vive in `tests/Feature` anche se concettualmente è un test di infrastruttura isolato — è l'unico modo per esercitare un vincolo `UNIQUE` reale di PostgreSQL.

**Interfaces:**
- Consumes: `DomainEvent`, `StoredEventRow` (Task 6), `ConcurrencyConflictException` (Task 8), tabella `event_store` (Task 8).
- Produces: `EventStore` interface (`append(string $aggregateId, int $expectedVersion, array $events): void`, `loadStream(string $aggregateId): array`), `PostgresEventStore` (constructor: `array $eventClassesByType` — mappa `event_type => class-string<DomainEvent>`). Consumato da `TransactionRepository` (Task 17) e registrato nel service provider (Task 24).

- [ ] **Step 1: Write the failing test**

Il test usa lo stesso `FakeEvent`/`FakeIncrementedEvent` pattern dei task precedenti per non dipendere dagli eventi reali di `Reconciliation`, non ancora scritti.

```php
<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class StoreFakeEvent extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $note = '',
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'store_fake.happened';
    }

    public function payload(): array
    {
        return ['note' => $this->note];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            $row->payload['note'],
        );
    }
}

function makeEventStore(): PostgresEventStore
{
    return new PostgresEventStore(['store_fake.happened' => StoreFakeEvent::class]);
}

it('appends events and loads them back in order', function () {
    $store = makeEventStore();
    $aggregateId = (string) Str::uuid();

    $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), 'c1', 'r1', 'first'),
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), 'c2', 'r1', 'second'),
    ]);

    $events = $store->loadStream($aggregateId);

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(DomainEvent::class)
        ->and($events[0]->payload()['note'])->toBe('first')
        ->and($events[1]->payload()['note'])->toBe('second');
});

it('rejects an append whose expected version does not match', function () {
    $store = makeEventStore();
    $aggregateId = (string) Str::uuid();

    $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), 'c1', 'r1'),
    ]);

    expect(fn () => $store->append($aggregateId, 0, [
        new StoreFakeEvent($aggregateId, new DateTimeImmutable(), Actor::system(), 'c2', 'r1'),
    ]))->toThrow(ConcurrencyConflictException::class);
});

it('returns an empty stream for an aggregate with no events', function () {
    expect(makeEventStore()->loadStream((string) Str::uuid()))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PostgresEventStoreTest`
Expected: FAIL — class `EventStore`/`PostgresEventStore` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\SharedKernel\Application;

use App\Modules\SharedKernel\Domain\DomainEvent;

interface EventStore
{
    /**
     * @param DomainEvent[] $events
     *
     * @throws \App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException
     */
    public function append(string $aggregateId, int $expectedVersion, array $events): void;

    /** @return DomainEvent[] */
    public function loadStream(string $aggregateId): array;
}
```

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ActorType;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresEventStore implements EventStore
{
    /** @param array<string, class-string<DomainEvent>> $eventClassesByType */
    public function __construct(private readonly array $eventClassesByType)
    {
    }

    public function append(string $aggregateId, int $expectedVersion, array $events): void
    {
        DB::transaction(function () use ($aggregateId, $expectedVersion, $events) {
            $version = $expectedVersion;

            foreach ($events as $event) {
                $version++;

                try {
                    DB::table('event_store')->insert([
                        'aggregate_id' => $aggregateId,
                        'version' => $version,
                        'event_type' => $event->eventType(),
                        'schema_version' => 1,
                        'payload' => json_encode($event->payload()),
                        'occurred_at' => $event->occurredAt(),
                        'actor_type' => $event->actor()->type->value,
                        'actor_id' => $event->actor()->id,
                        'causation_id' => $event->causationId(),
                        'correlation_id' => $event->correlationId(),
                        'recorded_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    throw new ConcurrencyConflictException($aggregateId, $version, $e);
                }
            }
        });
    }

    public function loadStream(string $aggregateId): array
    {
        $rows = DB::table('event_store')
            ->where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get();

        return $rows->map(function ($row) {
            $class = $this->eventClassesByType[$row->event_type]
                ?? throw new RuntimeException("Unknown event type: {$row->event_type}");

            $actor = $row->actor_type === ActorType::System->value
                ? Actor::system()
                : Actor::apiCaller($row->actor_id);

            return $class::fromStoredRow(new StoredEventRow(
                aggregateId: $row->aggregate_id,
                version: $row->version,
                eventType: $row->event_type,
                payload: json_decode($row->payload, true),
                occurredAt: new DateTimeImmutable($row->occurred_at),
                actor: $actor,
                causationId: $row->causation_id,
                correlationId: $row->correlation_id,
            ));
        })->all();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PostgresEventStoreTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Application/EventStore.php app/Modules/SharedKernel/Infrastructure/EventStore/PostgresEventStore.php tests/Feature/Modules/SharedKernel/PostgresEventStoreTest.php
git commit -m "feat(shared-kernel): add EventStore interface and PostgresEventStore (ADR-002, ADR-003)"
```

---

## Task 10: Reconciliation Domain — TransactionState enum ed eccezioni

**Files:**
- Create: `app/Modules/Reconciliation/Domain/TransactionState.php`
- Create: `app/Modules/Reconciliation/Domain/Exceptions/IllegalTransactionStateTransition.php`
- Create: `app/Modules/Reconciliation/Domain/Exceptions/TransactionNotFound.php`
- Create: `app/Modules/Reconciliation/Domain/Exceptions/InvalidResolutionCandidate.php`
- Test: nessuno dedicato — sono strutture dati pure, coperte indirettamente dai test del `Transaction` aggregate (Task 12–14)

**Interfaces:**
- Produces: `TransactionState` (backed enum: `Pending`, `Matched`, `Unmatched`, `NeedsReview`, `Reconciled`, `Rejected` — spec §5), `IllegalTransactionStateTransition` (readonly `$transactionId`, `$currentState`, `$expectedState`), `TransactionNotFound` (readonly `$transactionId`), `InvalidResolutionCandidate` (readonly `$expectedPaymentId`). Consumate da `Transaction` (Task 12–14) e dai controller HTTP (Task 25–27) per il mapping verso i codici di stato HTTP.

- [ ] **Step 1: Write the enum**

```php
<?php

namespace App\Modules\Reconciliation\Domain;

enum TransactionState: string
{
    case Pending = 'Pending';
    case Matched = 'Matched';
    case Unmatched = 'Unmatched';
    case NeedsReview = 'NeedsReview';
    case Reconciled = 'Reconciled';
    case Rejected = 'Rejected';
}
```

- [ ] **Step 2: Write the exceptions**

```php
<?php

namespace App\Modules\Reconciliation\Domain\Exceptions;

use App\Modules\Reconciliation\Domain\TransactionState;
use RuntimeException;

final class IllegalTransactionStateTransition extends RuntimeException
{
    public function __construct(
        public readonly string $transactionId,
        public readonly TransactionState $currentState,
        public readonly TransactionState $expectedState,
    ) {
        parent::__construct(
            "Transaction {$transactionId} is in state {$currentState->value}, expected {$expectedState->value}.",
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Exceptions;

use RuntimeException;

final class TransactionNotFound extends RuntimeException
{
    public function __construct(public readonly string $transactionId)
    {
        parent::__construct("Transaction {$transactionId} not found.");
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Exceptions;

use RuntimeException;

final class InvalidResolutionCandidate extends RuntimeException
{
    public function __construct(public readonly string $expectedPaymentId)
    {
        parent::__construct("{$expectedPaymentId} is not among the recorded candidates for this transaction.");
    }
}
```

- [ ] **Step 3: Verify autoloading**

Run: `php artisan tinker --execute="echo App\Modules\Reconciliation\Domain\TransactionState::Pending->value;"`
Expected output: `Pending`

- [ ] **Step 4: Commit**

```bash
git add app/Modules/Reconciliation/Domain/TransactionState.php app/Modules/Reconciliation/Domain/Exceptions/
git commit -m "feat(reconciliation): add TransactionState enum and domain exceptions"
```

---

## Task 11: Reconciliation Domain — eventi di dominio

**Files:**
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionImported.php`
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionMatched.php`
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionMarkedUnmatched.php`
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionMarkedAmbiguous.php`
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionReconciled.php`
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionRejected.php`
- Test: `tests/Unit/Modules/Reconciliation/DomainEventsTest.php`

I sei eventi hanno lo stesso payload esatto documentato nel technical design §3. Un solo test file copre round-trip payload→fromStoredRow per tutti e sei, dato che sono strutturalmente identici (nessuna logica da testare separatamente per evento).

**Interfaces:**
- Consumes: `AbstractDomainEvent`, `StoredEventRow`, `Actor` (Task 6, Task 3), `Currency` (Task 2).
- Produces: sei classi evento, ciascuna con `eventType(): string` e `payload(): array` che rispecchiano esattamente il technical design §3. Consumate da `Transaction` (Task 12–14) e da `PostgresEventStore`'s `$eventClassesByType` map (registrata nel Task 24).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Reconciliation\Domain\Events\TransactionMatched;
use App\Modules\Reconciliation\Domain\Events\TransactionReconciled;
use App\Modules\Reconciliation\Domain\Events\TransactionRejected;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;

function envelope(array $payload, string $eventType): StoredEventRow
{
    return new StoredEventRow(
        aggregateId: 'txn-1',
        version: 1,
        eventType: $eventType,
        payload: $payload,
        occurredAt: new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        actor: Actor::system(),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );
}

it('round-trips TransactionImported', function () {
    $event = new TransactionImported(
        'txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1',
        amountMinorUnits: 12345,
        currency: Currency::EUR,
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: 'abc123',
        rawRowChecksum: 'def456',
    );

    expect($event->eventType())->toBe('transaction.imported')
        ->and($event->payload())->toBe([
            'transaction_id' => 'txn-1',
            'amount_minor_units' => 12345,
            'currency' => 'EUR',
            'reference' => 'REF-1',
            'statement_date' => '2026-07-31',
            'occurrence_index' => 0,
            'idempotency_key' => 'abc123',
            'raw_row_checksum' => 'def456',
        ]);

    $reconstructed = TransactionImported::fromStoredRow(envelope($event->payload(), 'transaction.imported'));

    expect($reconstructed->reference)->toBe('REF-1')
        ->and($reconstructed->currency)->toBe(Currency::EUR)
        ->and($reconstructed->statementDate->format('Y-m-d'))->toBe('2026-07-31');
});

it('round-trips TransactionMatched', function () {
    $event = new TransactionMatched('txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1', expectedPaymentId: 'ep-1', matchType: 'exact');

    expect($event->eventType())->toBe('transaction.matched')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'expected_payment_id' => 'ep-1', 'match_type' => 'exact']);

    $reconstructed = TransactionMatched::fromStoredRow(envelope($event->payload(), 'transaction.matched'));
    expect($reconstructed->expectedPaymentId)->toBe('ep-1');
});

it('round-trips TransactionMarkedUnmatched', function () {
    $event = new TransactionMarkedUnmatched('txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1', reason: 'no_candidate_found');

    expect($event->eventType())->toBe('transaction.marked_unmatched')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'reason' => 'no_candidate_found']);

    $reconstructed = TransactionMarkedUnmatched::fromStoredRow(envelope($event->payload(), 'transaction.marked_unmatched'));
    expect($reconstructed->reason)->toBe('no_candidate_found');
});

it('round-trips TransactionMarkedAmbiguous', function () {
    $event = new TransactionMarkedAmbiguous('txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1', candidateExpectedPaymentIds: ['ep-1', 'ep-2'], reason: 'multiple_candidates');

    expect($event->eventType())->toBe('transaction.marked_ambiguous')
        ->and($event->payload())->toBe([
            'transaction_id' => 'txn-1',
            'candidate_expected_payment_ids' => ['ep-1', 'ep-2'],
            'reason' => 'multiple_candidates',
        ]);

    $reconstructed = TransactionMarkedAmbiguous::fromStoredRow(envelope($event->payload(), 'transaction.marked_ambiguous'));
    expect($reconstructed->candidateExpectedPaymentIds)->toBe(['ep-1', 'ep-2']);
});

it('round-trips TransactionReconciled', function () {
    $event = new TransactionReconciled('txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1', expectedPaymentId: 'ep-1', resolution: 'auto');

    expect($event->eventType())->toBe('transaction.reconciled')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'expected_payment_id' => 'ep-1', 'resolution' => 'auto']);

    $reconstructed = TransactionReconciled::fromStoredRow(envelope($event->payload(), 'transaction.reconciled'));
    expect($reconstructed->resolution)->toBe('auto');
});

it('round-trips TransactionRejected', function () {
    $event = new TransactionRejected('txn-1', new DateTimeImmutable(), Actor::system(), 'c1', 'r1', reason: 'duplicate payment claimed elsewhere');

    expect($event->eventType())->toBe('transaction.rejected')
        ->and($event->payload())->toBe(['transaction_id' => 'txn-1', 'reason' => 'duplicate payment claimed elsewhere']);

    $reconstructed = TransactionRejected::fromStoredRow(envelope($event->payload(), 'transaction.rejected'));
    expect($reconstructed->reason)->toBe('duplicate payment claimed elsewhere');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DomainEventsTest`
Expected: FAIL — event classes not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionImported extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
        public readonly string $reference,
        public readonly DateTimeImmutable $statementDate,
        public readonly int $occurrenceIndex,
        public readonly string $idempotencyKey,
        public readonly string $rawRowChecksum,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.imported';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'amount_minor_units' => $this->amountMinorUnits,
            'currency' => $this->currency->value,
            'reference' => $this->reference,
            'statement_date' => $this->statementDate->format('Y-m-d'),
            'occurrence_index' => $this->occurrenceIndex,
            'idempotency_key' => $this->idempotencyKey,
            'raw_row_checksum' => $this->rawRowChecksum,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            amountMinorUnits: $row->payload['amount_minor_units'],
            currency: Currency::from($row->payload['currency']),
            reference: $row->payload['reference'],
            statementDate: new DateTimeImmutable($row->payload['statement_date']),
            occurrenceIndex: $row->payload['occurrence_index'],
            idempotencyKey: $row->payload['idempotency_key'],
            rawRowChecksum: $row->payload['raw_row_checksum'],
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionMatched extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $expectedPaymentId,
        public readonly string $matchType,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.matched';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'expected_payment_id' => $this->expectedPaymentId,
            'match_type' => $this->matchType,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            expectedPaymentId: $row->payload['expected_payment_id'],
            matchType: $row->payload['match_type'],
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionMarkedUnmatched extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $reason,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.marked_unmatched';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'reason' => $this->reason,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            reason: $row->payload['reason'],
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionMarkedAmbiguous extends AbstractDomainEvent
{
    /** @param string[] $candidateExpectedPaymentIds */
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly array $candidateExpectedPaymentIds,
        public readonly string $reason,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.marked_ambiguous';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'candidate_expected_payment_ids' => $this->candidateExpectedPaymentIds,
            'reason' => $this->reason,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            candidateExpectedPaymentIds: $row->payload['candidate_expected_payment_ids'],
            reason: $row->payload['reason'],
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionReconciled extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $expectedPaymentId,
        public readonly string $resolution,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.reconciled';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'expected_payment_id' => $this->expectedPaymentId,
            'resolution' => $this->resolution,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            expectedPaymentId: $row->payload['expected_payment_id'],
            resolution: $row->payload['resolution'],
        );
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRow;
use DateTimeImmutable;

final class TransactionRejected extends AbstractDomainEvent
{
    public function __construct(
        string $aggregateId,
        DateTimeImmutable $occurredAt,
        Actor $actor,
        string $causationId,
        string $correlationId,
        public readonly string $reason,
    ) {
        parent::__construct($aggregateId, $occurredAt, $actor, $causationId, $correlationId);
    }

    public function eventType(): string
    {
        return 'transaction.rejected';
    }

    public function payload(): array
    {
        return [
            'transaction_id' => $this->aggregateId(),
            'reason' => $this->reason,
        ];
    }

    public static function fromStoredRow(StoredEventRow $row): static
    {
        return new self(
            $row->aggregateId,
            $row->occurredAt,
            $row->actor,
            $row->causationId,
            $row->correlationId,
            reason: $row->payload['reason'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DomainEventsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Domain/Events/ tests/Unit/Modules/Reconciliation/DomainEventsTest.php
git commit -m "feat(reconciliation): add Transaction domain events"
```

---

## Task 12: Reconciliation Domain — Transaction aggregate: `import()` e stato `Pending`

**Files:**
- Create: `app/Modules/Reconciliation/Domain/Transaction.php`
- Test: `tests/Unit/Modules/Reconciliation/TransactionTest.php`

**Interfaces:**
- Consumes: `AggregateRoot` (Task 7), tutti gli eventi (Task 11), `TransactionState`, le eccezioni del Task 10, `Actor`, `Money`, `Currency`, `TransactionId`, `IdempotencyKey` (SharedKernel).
- Produces: `Transaction::import(...): self`, `aggregateId()`, `state()`, `money()`, `reference()`, `statementDate()`, `importedAt()`. Esteso nei Task 13–14 con i comandi di matching e risoluzione. Consumato da `TransactionRepository` (Task 17), `MatchingEngine`/`MatchPendingTransactionJob` (Task 16, 22), `ResolveReviewService` (Task 23).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;

function importedTransaction(): Transaction
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    return Transaction::import(
        id: $id,
        money: new Money(12345, Currency::EUR),
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'causation-1',
        correlationId: 'correlation-1',
    );
}

it('is born Pending and records exactly one TransactionImported event', function () {
    $transaction = importedTransaction();

    expect($transaction->state())->toBe(TransactionState::Pending)
        ->and($transaction->reference())->toBe('REF-1')
        ->and($transaction->money()->amountMinorUnits)->toBe(12345)
        ->and($transaction->money()->currency)->toBe(Currency::EUR)
        ->and($transaction->statementDate()->format('Y-m-d'))->toBe('2026-07-31')
        ->and($transaction->version())->toBe(1);

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(\App\Modules\Reconciliation\Domain\Events\TransactionImported::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionTest`
Expected: FAIL — class `Transaction` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Domain;

use App\Modules\Reconciliation\Domain\Events\TransactionImported;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use DateTimeImmutable;
use InvalidArgumentException;

final class Transaction extends AggregateRoot
{
    private string $id;
    private TransactionState $state;
    private Money $money;
    private string $reference;
    private DateTimeImmutable $statementDate;
    private DateTimeImmutable $importedAt;

    public static function import(
        TransactionId $id,
        Money $money,
        string $reference,
        DateTimeImmutable $statementDate,
        int $occurrenceIndex,
        IdempotencyKey $idempotencyKey,
        string $rawRowChecksum,
        Actor $actor,
        string $causationId,
        string $correlationId,
    ): self {
        $transaction = self::createEmpty();

        $transaction->record(new TransactionImported(
            $id->value,
            new DateTimeImmutable(),
            $actor,
            $causationId,
            $correlationId,
            amountMinorUnits: $money->amountMinorUnits,
            currency: $money->currency,
            reference: $reference,
            statementDate: $statementDate,
            occurrenceIndex: $occurrenceIndex,
            idempotencyKey: $idempotencyKey->value,
            rawRowChecksum: $rawRowChecksum,
        ));

        return $transaction;
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function state(): TransactionState
    {
        return $this->state;
    }

    public function money(): Money
    {
        return $this->money;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function statementDate(): DateTimeImmutable
    {
        return $this->statementDate;
    }

    public function importedAt(): DateTimeImmutable
    {
        return $this->importedAt;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransactionImported => $this->applyImported($event),
            default => throw new InvalidArgumentException('Unknown event: ' . $event::class),
        };
    }

    private function applyImported(TransactionImported $event): void
    {
        $this->id = $event->aggregateId();
        $this->state = TransactionState::Pending;
        $this->money = new Money($event->amountMinorUnits, $event->currency);
        $this->reference = $event->reference;
        $this->statementDate = $event->statementDate;
        $this->importedAt = $event->occurredAt();
    }

    protected static function createEmpty(): static
    {
        return new self();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Domain/Transaction.php tests/Unit/Modules/Reconciliation/TransactionTest.php
git commit -m "feat(reconciliation): add Transaction aggregate — import() and Pending state"
```

---

## Task 13: Reconciliation Domain — Transaction aggregate: comandi di matching

**Files:**
- Modify: `app/Modules/Reconciliation/Domain/Transaction.php`
- Modify: `tests/Unit/Modules/Reconciliation/TransactionTest.php`

**Interfaces:**
- Produces (aggiunte a `Transaction`): `markMatched(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void`, `markUnmatched(Actor $actor, string $causationId, string $correlationId): void`, `markAmbiguous(array $candidateExpectedPaymentIds, string $reason, Actor $actor, string $causationId, string $correlationId): void`, `matchedExpectedPaymentId(): ?string`. Consumato da `MatchingEngine`/`MatchPendingTransactionJob` (Task 16, 22).

- [ ] **Step 1: Write the failing tests**

Aggiungi in fondo a `tests/Unit/Modules/Reconciliation/TransactionTest.php`:

```php
it('auto-reconciles on an exact match', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markMatched('ep-1', Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(\App\Modules\Reconciliation\Domain\TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe('ep-1')
        ->and($transaction->version())->toBe(3);

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(\App\Modules\Reconciliation\Domain\Events\TransactionMatched::class)
        ->and($events[1])->toBeInstanceOf(\App\Modules\Reconciliation\Domain\Events\TransactionReconciled::class)
        ->and($events[1]->resolution)->toBe('auto');
});

it('becomes Unmatched when no candidate is found', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(\App\Modules\Reconciliation\Domain\TransactionState::Unmatched);
});

it('becomes NeedsReview with recorded candidates when ambiguous', function () {
    $transaction = importedTransaction();
    $transaction->releaseEvents();

    $transaction->markAmbiguous(['ep-1', 'ep-2'], 'multiple_candidates', Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(\App\Modules\Reconciliation\Domain\TransactionState::NeedsReview)
        ->and($transaction->candidateExpectedPaymentIds())->toBe(['ep-1', 'ep-2']);
});

it('rejects markMatched when the transaction is not Pending', function () {
    $transaction = importedTransaction();
    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect(fn () => $transaction->markMatched('ep-1', Actor::system(), 'c3', 'r1'))
        ->toThrow(\App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionTest`
Expected: FAIL — `markMatched`/`markUnmatched`/`markAmbiguous`/`matchedExpectedPaymentId`/`candidateExpectedPaymentIds` not found on `Transaction`.

- [ ] **Step 3: Write minimal implementation**

Aggiungi questi `use` all'inizio di `Transaction.php`:

```php
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Reconciliation\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Reconciliation\Domain\Events\TransactionMatched;
use App\Modules\Reconciliation\Domain\Events\TransactionReconciled;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
```

Aggiungi la proprietà, subito dopo `private DateTimeImmutable $importedAt;`:

```php
    private ?string $matchedExpectedPaymentId = null;
    /** @var string[] */
    private array $candidateExpectedPaymentIds = [];
```

Aggiungi questi metodi pubblici, subito dopo `import()`:

```php
    public function markMatched(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMatched(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            matchType: 'exact',
        ));

        $this->record(new TransactionReconciled(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            resolution: 'auto',
        ));
    }

    public function markUnmatched(Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMarkedUnmatched(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            reason: 'no_candidate_found',
        ));
    }

    /** @param string[] $candidateExpectedPaymentIds */
    public function markAmbiguous(array $candidateExpectedPaymentIds, string $reason, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::Pending);

        $this->record(new TransactionMarkedAmbiguous(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            candidateExpectedPaymentIds: $candidateExpectedPaymentIds,
            reason: $reason,
        ));
    }
```

Aggiungi questi getter, subito dopo `importedAt()`:

```php
    public function matchedExpectedPaymentId(): ?string
    {
        return $this->matchedExpectedPaymentId;
    }

    /** @return string[] */
    public function candidateExpectedPaymentIds(): array
    {
        return $this->candidateExpectedPaymentIds;
    }
```

Sostituisci il metodo `apply()` con:

```php
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransactionImported => $this->applyImported($event),
            $event instanceof TransactionMatched => $this->applyMatched($event),
            $event instanceof TransactionMarkedUnmatched => $this->state = TransactionState::Unmatched,
            $event instanceof TransactionMarkedAmbiguous => $this->applyMarkedAmbiguous($event),
            $event instanceof TransactionReconciled => $this->applyReconciled($event),
            default => throw new InvalidArgumentException('Unknown event: ' . $event::class),
        };
    }
```

Aggiungi questi metodi privati, dopo `applyImported()`:

```php
    private function applyMatched(TransactionMatched $event): void
    {
        $this->state = TransactionState::Matched;
        $this->matchedExpectedPaymentId = $event->expectedPaymentId;
    }

    private function applyMarkedAmbiguous(TransactionMarkedAmbiguous $event): void
    {
        $this->state = TransactionState::NeedsReview;
        $this->candidateExpectedPaymentIds = $event->candidateExpectedPaymentIds;
    }

    private function applyReconciled(TransactionReconciled $event): void
    {
        $this->state = TransactionState::Reconciled;
        $this->matchedExpectedPaymentId = $event->expectedPaymentId;
    }

    private function assertState(TransactionState $expected): void
    {
        if ($this->state !== $expected) {
            throw new IllegalTransactionStateTransition($this->id, $this->state, $expected);
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Domain/Transaction.php tests/Unit/Modules/Reconciliation/TransactionTest.php
git commit -m "feat(reconciliation): add Transaction matching commands (markMatched/markUnmatched/markAmbiguous)"
```

---

## Task 14: Reconciliation Domain — Transaction aggregate: risoluzione manuale

**Files:**
- Modify: `app/Modules/Reconciliation/Domain/Transaction.php`
- Modify: `tests/Unit/Modules/Reconciliation/TransactionTest.php`

**Interfaces:**
- Produces (aggiunte a `Transaction`): `resolveByConfirming(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void`, `resolveByRejecting(string $reason, Actor $actor, string $causationId, string $correlationId): void`. Lancia `InvalidResolutionCandidate` (Task 10) se `expectedPaymentId` non è tra i candidati registrati; lancia `IllegalTransactionStateTransition` se lo stato non è `NeedsReview`. Consumato da `ResolveReviewService` (Task 23).

- [ ] **Step 1: Write the failing tests**

Aggiungi in fondo a `tests/Unit/Modules/Reconciliation/TransactionTest.php`:

```php
function needsReviewTransaction(): Transaction
{
    $transaction = importedTransaction();
    $transaction->markAmbiguous(['ep-1', 'ep-2'], 'multiple_candidates', Actor::system(), 'c2', 'r1');
    $transaction->releaseEvents();

    return $transaction;
}

it('reconciles manually when confirming a recorded candidate', function () {
    $transaction = needsReviewTransaction();

    $transaction->resolveByConfirming('ep-1', Actor::apiCaller('reviewer-1'), 'c3', 'r2');

    expect($transaction->state())->toBe(TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe('ep-1');

    $events = $transaction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->resolution)->toBe('manual');
});

it('rejects confirming a candidate that was never recorded', function () {
    $transaction = needsReviewTransaction();

    expect(fn () => $transaction->resolveByConfirming('ep-not-a-candidate', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(\App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate::class);
});

it('rejects when the transaction is not NeedsReview', function () {
    $transaction = importedTransaction();
    $transaction->markUnmatched(Actor::system(), 'c2', 'r1');

    expect(fn () => $transaction->resolveByConfirming('ep-1', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(\App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition::class);
});

it('rejects with a reason', function () {
    $transaction = needsReviewTransaction();

    $transaction->resolveByRejecting('duplicate payment claimed elsewhere', Actor::apiCaller('reviewer-1'), 'c3', 'r2');

    expect($transaction->state())->toBe(TransactionState::Rejected);

    $events = $transaction->releaseEvents();
    expect($events[0])->toBeInstanceOf(\App\Modules\Reconciliation\Domain\Events\TransactionRejected::class)
        ->and($events[0]->reason)->toBe('duplicate payment claimed elsewhere');
});

it('rejects rejection with an empty reason', function () {
    $transaction = needsReviewTransaction();

    expect(fn () => $transaction->resolveByRejecting('   ', Actor::apiCaller('reviewer-1'), 'c3', 'r2'))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionTest`
Expected: FAIL — `resolveByConfirming`/`resolveByRejecting` not found on `Transaction`.

- [ ] **Step 3: Write minimal implementation**

Aggiungi questo `use` a `Transaction.php`:

```php
use App\Modules\Reconciliation\Domain\Events\TransactionRejected;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
```

Aggiungi questi metodi pubblici, subito dopo `markAmbiguous()`:

```php
    public function resolveByConfirming(string $expectedPaymentId, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::NeedsReview);

        if (!in_array($expectedPaymentId, $this->candidateExpectedPaymentIds, true)) {
            throw new InvalidResolutionCandidate($expectedPaymentId);
        }

        $this->record(new TransactionReconciled(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            expectedPaymentId: $expectedPaymentId,
            resolution: 'manual',
        ));
    }

    public function resolveByRejecting(string $reason, Actor $actor, string $causationId, string $correlationId): void
    {
        $this->assertState(TransactionState::NeedsReview);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $this->record(new TransactionRejected(
            $this->id, new DateTimeImmutable(), $actor, $causationId, $correlationId,
            reason: $reason,
        ));
    }
```

Aggiungi il branch mancante nel metodo `apply()`:

```php
            $event instanceof TransactionRejected => $this->state = TransactionState::Rejected,
```
(va inserito prima del ramo `default =>`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionTest`
Expected: PASS — l'intera suite di `TransactionTest.php` (Task 12–14) è verde.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Domain/Transaction.php tests/Unit/Modules/Reconciliation/TransactionTest.php
git commit -m "feat(reconciliation): add Transaction manual review commands (resolveByConfirming/resolveByRejecting)"
```

---

## Task 15: Reconciliation Domain — ExpectedPayment (plain Eloquent model)

**Files:**
- Create: `app/Modules/Reconciliation/Domain/ExpectedPayment.php`
- Create: `database/migrations/<timestamp>_create_expected_payments_table.php`
- Create: `database/factories/ExpectedPaymentFactory.php`
- Test: `tests/Feature/Modules/Reconciliation/ExpectedPaymentTest.php`

Per il technical design addendum §1, `ExpectedPayment` è un plain Eloquent model che vive in `Domain/`, non dietro un repository — coerente con ADR-002 ("Expected Payments remain plain Eloquent models... non sono il soggetto del design state-machine/audit qui dimostrato"). Dati seed/fixture, non un modulo gestito (spec §2).

**Interfaces:**
- Produces: `ExpectedPayment` (Eloquent model: `id` uuid PK, `amount_minor_units`, `currency`, `reference`), `ExpectedPaymentFactory`. Consumato da `MatchTransactionService` (Task 16).

- [ ] **Step 1: Write the migration**

```bash
php artisan make:migration create_expected_payments_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expected_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('amount_minor_units');
            $table->string('currency');
            $table->string('reference');
            $table->timestamps();

            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_payments');
    }
};
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an expected payment via its factory with a uuid id', function () {
    $payment = ExpectedPayment::factory()->create([
        'reference' => 'REF-1',
        'amount_minor_units' => 12345,
        'currency' => 'EUR',
    ]);

    expect($payment->id)->toBeString()
        ->and(strlen($payment->id))->toBe(36)
        ->and(ExpectedPayment::query()->where('reference', 'REF-1')->count())->toBe(1);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=ExpectedPaymentTest`
Expected: FAIL — class `ExpectedPayment` not found.

- [ ] **Step 4: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Domain;

use Database\Factories\ExpectedPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpectedPayment extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'amount_minor_units', 'currency', 'reference'];

    protected static function newFactory(): ExpectedPaymentFactory
    {
        return ExpectedPaymentFactory::new();
    }
}
```

```php
<?php

namespace Database\Factories;

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpectedPayment> */
class ExpectedPaymentFactory extends Factory
{
    protected $model = ExpectedPayment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'amount_minor_units' => $this->faker->numberBetween(1000, 100000),
            'currency' => 'EUR',
            'reference' => strtoupper('REF-' . $this->faker->unique()->numberBetween(1000, 9999)),
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ExpectedPaymentTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Reconciliation/Domain/ExpectedPayment.php database/migrations/*_create_expected_payments_table.php database/factories/ExpectedPaymentFactory.php tests/Feature/Modules/Reconciliation/ExpectedPaymentTest.php
git commit -m "feat(reconciliation): add ExpectedPayment model, migration, and factory"
```

---

## Task 16: Reconciliation Application — MatchTransactionService

**Files:**
- Create: `app/Modules/Reconciliation/Application/MatchTransactionService.php`
- Test: `tests/Feature/Modules/Reconciliation/MatchTransactionServiceTest.php`

Implementa esattamente la decisione di matching dello spec §6.2 — il conteggio dei candidati decide prima dell'importo (più di un candidato è ambiguo anche se uno di essi corrisponde esattamente per importo).

**Interfaces:**
- Consumes: `Transaction` (Task 12–14), `ExpectedPayment` (Task 15), `Actor` (SharedKernel).
- Produces: `MatchTransactionService::match(Transaction $transaction, Actor $actor, string $causationId, string $correlationId): void` — muta l'aggregate registrando l'evento appropriato, non salva. Consumato da `MatchPendingTransactionJob` (Task 22).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\MatchTransactionService;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pendingTransaction(string $reference = 'REF-1', int $amountMinorUnits = 12345, Currency $currency = Currency::EUR): Transaction
{
    $key = IdempotencyKey::forStatementRow($reference, $amountMinorUnits, $currency, new DateTimeImmutable('2026-07-31'), 0);

    $transaction = Transaction::import(
        id: TransactionId::deriveFrom($key),
        money: new Money($amountMinorUnits, $currency),
        reference: $reference,
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'c1',
        correlationId: 'r1',
    );
    $transaction->releaseEvents();

    return $transaction;
}

it('auto-reconciles when exactly one candidate matches the amount exactly', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Reconciled);
});

it('marks Unmatched when there is no candidate', function () {
    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::Unmatched);
});

it('marks NeedsReview with reason partial_amount_match when the single candidate amount differs', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::NeedsReview);
});

it('marks NeedsReview with reason multiple_candidates when several candidates share the reference, even if one matches exactly', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $transaction = pendingTransaction();
    (new MatchTransactionService())->match($transaction, Actor::system(), 'c2', 'r1');

    expect($transaction->state())->toBe(TransactionState::NeedsReview)
        ->and($transaction->candidateExpectedPaymentIds())->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MatchTransactionServiceTest`
Expected: FAIL — class `MatchTransactionService` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Domain\Actor;

final class MatchTransactionService
{
    public function match(Transaction $transaction, Actor $actor, string $causationId, string $correlationId): void
    {
        $candidates = ExpectedPayment::query()
            ->where('reference', $transaction->reference())
            ->get();

        match ($candidates->count()) {
            0 => $transaction->markUnmatched($actor, $causationId, $correlationId),
            1 => $this->resolveSingleCandidate($transaction, $candidates->first(), $actor, $causationId, $correlationId),
            default => $transaction->markAmbiguous(
                $candidates->pluck('id')->all(),
                'multiple_candidates',
                $actor,
                $causationId,
                $correlationId,
            ),
        };
    }

    private function resolveSingleCandidate(
        Transaction $transaction,
        ExpectedPayment $candidate,
        Actor $actor,
        string $causationId,
        string $correlationId,
    ): void {
        $money = $transaction->money();

        if ($candidate->amount_minor_units === $money->amountMinorUnits && $candidate->currency === $money->currency->value) {
            $transaction->markMatched($candidate->id, $actor, $causationId, $correlationId);

            return;
        }

        $transaction->markAmbiguous([$candidate->id], 'partial_amount_match', $actor, $causationId, $correlationId);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MatchTransactionServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Application/MatchTransactionService.php tests/Feature/Modules/Reconciliation/MatchTransactionServiceTest.php
git commit -m "feat(reconciliation): add MatchTransactionService (spec §6.2 matching decision)"
```

---

## Task 17: Reconciliation Application — TransactionRepository

**Files:**
- Create: `app/Modules/Reconciliation/Domain/Events/TransactionEventTypes.php`
- Create: `app/Modules/Reconciliation/Application/TransactionRepository.php`
- Test: `tests/Feature/Modules/Reconciliation/TransactionRepositoryTest.php`

`TransactionEventTypes` è la mappa `event_type => class-string<DomainEvent>` richiesta dal costruttore di `PostgresEventStore` (Task 9). Vive come classe reale (non come helper di test) perché serve sia ai test sia al binding nel service provider (Task 24) — un'unica fonte di verità per l'elenco dei sei eventi.

**Interfaces:**
- Consumes: `EventStore` (Task 9), `Transaction` (Task 12–14), `TransactionId` (SharedKernel), i sei eventi (Task 11).
- Produces: `TransactionEventTypes::map(): array<string, class-string>`, `TransactionRepository::__construct(EventStore $eventStore)`, `find(TransactionId $id): ?Transaction`, `save(Transaction $transaction): void`. Consumato da `ImportStatementService` (Task 21), `MatchPendingTransactionJob` (Task 22), `ResolveReviewService` (Task 23), i controller HTTP (Task 25–27), il service provider (Task 24), e ogni test successivo che deve costruire un `PostgresEventStore`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function repository(): TransactionRepository
{
    return new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));
}

it('returns null for an unknown transaction', function () {
    expect(repository()->find(TransactionId::fromString((string) Str::uuid())))->toBeNull();
});

it('saves a newly imported transaction and finds it again', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id,
        money: new Money(12345, Currency::EUR),
        reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'),
        occurrenceIndex: 0,
        idempotencyKey: $key,
        rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'),
        causationId: 'c1',
        correlationId: 'r1',
    );

    repository()->save($transaction);

    $found = repository()->find($id);

    expect($found)->not->toBeNull()
        ->and($found->reference())->toBe('REF-1')
        ->and($found->version())->toBe(1);
});

it('persists events recorded across multiple save calls at the correct version', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    repository()->save($transaction);

    $reloaded = repository()->find($id);
    $reloaded->markMatched('ep-1', Actor::system(), 'c2', 'r1');
    repository()->save($reloaded);

    $final = repository()->find($id);

    expect($final->version())->toBe(3)
        ->and($final->state())->toBe(TransactionState::Reconciled);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionRepositoryTest`
Expected: FAIL — classes `TransactionEventTypes`/`TransactionRepository` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Domain\Events;

final class TransactionEventTypes
{
    /** @return array<string, class-string<\App\Modules\SharedKernel\Domain\DomainEvent>> */
    public static function map(): array
    {
        return [
            'transaction.imported' => TransactionImported::class,
            'transaction.matched' => TransactionMatched::class,
            'transaction.marked_unmatched' => TransactionMarkedUnmatched::class,
            'transaction.marked_ambiguous' => TransactionMarkedAmbiguous::class,
            'transaction.reconciled' => TransactionReconciled::class,
            'transaction.rejected' => TransactionRejected::class,
        ];
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\TransactionId;

final class TransactionRepository
{
    public function __construct(private readonly EventStore $eventStore)
    {
    }

    public function find(TransactionId $id): ?Transaction
    {
        $events = $this->eventStore->loadStream($id->value);

        if ($events === []) {
            return null;
        }

        return Transaction::reconstituteFromStream($events);
    }

    public function save(Transaction $transaction): void
    {
        $events = $transaction->releaseEvents();

        if ($events === []) {
            return;
        }

        $expectedVersion = $transaction->version() - count($events);

        $this->eventStore->append($transaction->aggregateId(), $expectedVersion, $events);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionRepositoryTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Domain/Events/TransactionEventTypes.php app/Modules/Reconciliation/Application/TransactionRepository.php tests/Feature/Modules/Reconciliation/TransactionRepositoryTest.php
git commit -m "feat(reconciliation): add TransactionEventTypes map and TransactionRepository"
```

---

## Task 18: Reconciliation Infrastructure — read model e TransactionReadModelProjector

**Files:**
- Create: `database/migrations/<timestamp>_create_transactions_read_model_table.php`
- Create: `app/Modules/Reconciliation/Infrastructure/Persistence/TransactionProjection.php`
- Create: `app/Modules/Reconciliation/Infrastructure/TransactionReadModelProjector.php`
- Test: `tests/Feature/Modules/Reconciliation/TransactionReadModelProjectorTest.php`

Projector sincrono, invocato nello stesso request/job dell'append (decisione di brainstorming). Schema esattamente come da technical design §2.

**Interfaces:**
- Consumes: `Transaction` (Task 12–14).
- Produces: tabella `transactions_read_model`, `TransactionProjection` (Eloquent), `TransactionReadModelProjector::project(Transaction $transaction): void`. Consumato da `ImportStatementService` (Task 21), `MatchPendingTransactionJob` (Task 22), `ResolveReviewService` (Task 23), `TransactionsController` (Task 26).

- [ ] **Step 1: Write the migration**

```bash
php artisan make:migration create_transactions_read_model_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_read_model', function (Blueprint $table) {
            $table->uuid('transaction_id')->primary();
            $table->string('state');
            $table->unsignedInteger('version');
            $table->bigInteger('amount_minor_units');
            $table->string('currency');
            $table->string('reference');
            $table->date('statement_date');
            $table->uuid('matched_expected_payment_id')->nullable();
            $table->timestampTz('imported_at');
            $table->timestampTz('updated_at');

            $table->index('state');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_read_model');
    }
};
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('projects a Pending transaction into the read model', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );

    (new TransactionReadModelProjector())->project($transaction);

    $row = TransactionProjection::query()->find($id->value);

    expect($row)->not->toBeNull()
        ->and($row->state)->toBe('Pending')
        ->and($row->amount_minor_units)->toBe(12345)
        ->and($row->currency)->toBe('EUR')
        ->and($row->reference)->toBe('REF-1');
});

it('overwrites the row on a later projection of the same aggregate', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    $projector = new TransactionReadModelProjector();
    $projector->project($transaction);

    $transaction->markMatched('ep-1', Actor::system(), 'c2', 'r1');
    $projector->project($transaction);

    expect(TransactionProjection::query()->count())->toBe(1)
        ->and(TransactionProjection::query()->find($id->value)->state)->toBe('Reconciled');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=TransactionReadModelProjectorTest`
Expected: FAIL — classes not found.

- [ ] **Step 4: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class TransactionProjection extends Model
{
    protected $table = 'transactions_read_model';

    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'state',
        'version',
        'amount_minor_units',
        'currency',
        'reference',
        'statement_date',
        'matched_expected_payment_id',
        'imported_at',
        'updated_at',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'imported_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;

final class TransactionReadModelProjector
{
    public function project(Transaction $transaction): void
    {
        TransactionProjection::query()->updateOrInsert(
            ['transaction_id' => $transaction->aggregateId()],
            [
                'state' => $transaction->state()->value,
                'version' => $transaction->version(),
                'amount_minor_units' => $transaction->money()->amountMinorUnits,
                'currency' => $transaction->money()->currency->value,
                'reference' => $transaction->reference(),
                'statement_date' => $transaction->statementDate()->format('Y-m-d'),
                'matched_expected_payment_id' => $transaction->matchedExpectedPaymentId(),
                'imported_at' => $transaction->importedAt(),
                'updated_at' => now(),
            ],
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TransactionReadModelProjectorTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/*_create_transactions_read_model_table.php app/Modules/Reconciliation/Infrastructure/Persistence/TransactionProjection.php app/Modules/Reconciliation/Infrastructure/TransactionReadModelProjector.php tests/Feature/Modules/Reconciliation/TransactionReadModelProjectorTest.php
git commit -m "feat(reconciliation): add transactions_read_model migration and synchronous projector"
```

---

## Task 19: Reconciliation Infrastructure — CsvStatementParser

**Files:**
- Create: `app/Modules/Reconciliation/Infrastructure/StatementLine.php`
- Create: `app/Modules/Reconciliation/Infrastructure/MalformedStatementException.php`
- Create: `app/Modules/Reconciliation/Infrastructure/CsvStatementParser.php`
- Test: `tests/Feature/Modules/Reconciliation/CsvStatementParserTest.php`

Parsing puramente lessicale: struttura le righe, non ne valida il contenuto (quello è `StatementRowValidator`, Task 20). Colonne CSV richieste: `reference,amount_minor_units,currency,statement_date` (ADR-005, technical design §4).

**Interfaces:**
- Produces: `StatementLine` (readonly DTO: `$rowNumber`, `$reference`, `$amountMinorUnits` (string, non ancora tipizzato), `$currency` (string), `$statementDate` (string), `$rawLine`), `MalformedStatementException` (readonly `$errors` string[]), `CsvStatementParser::parse(string $csvContents): StatementLine[]`. Consumato da `StatementRowValidator` (Task 20) e `ImportStatementService` (Task 21).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MalformedStatementException;

it('parses each data row into a StatementLine, 1-indexed excluding the header', function () {
    $csv = <<<CSV
    reference,amount_minor_units,currency,statement_date
    REF-1,12345,EUR,2026-07-31
    REF-2,500,USD,2026-08-01
    CSV;

    $lines = (new CsvStatementParser())->parse($csv);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->rowNumber)->toBe(1)
        ->and($lines[0]->reference)->toBe('REF-1')
        ->and($lines[0]->amountMinorUnits)->toBe('12345')
        ->and($lines[0]->currency)->toBe('EUR')
        ->and($lines[0]->statementDate)->toBe('2026-07-31')
        ->and($lines[1]->rowNumber)->toBe(2);
});

it('skips blank lines', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\n\nREF-2,500,USD,2026-08-01\n";

    expect((new CsvStatementParser())->parse($csv))->toHaveCount(2);
});

it('reports every missing required column', function () {
    $csv = "reference,amount_minor_units\nREF-1,12345";

    try {
        (new CsvStatementParser())->parse($csv);
        $this->fail('Expected MalformedStatementException.');
    } catch (MalformedStatementException $e) {
        expect($e->errors)->toContain('Missing required column: currency')
            ->and($e->errors)->toContain('Missing required column: statement_date');
    }
});

it('rejects an empty file', function () {
    expect(fn () => (new CsvStatementParser())->parse(''))->toThrow(MalformedStatementException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CsvStatementParserTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

final class StatementLine
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $reference,
        public readonly string $amountMinorUnits,
        public readonly string $currency,
        public readonly string $statementDate,
        public readonly string $rawLine,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

use RuntimeException;

final class MalformedStatementException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The statement file is structurally invalid: ' . implode(' ', $errors));
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

final class CsvStatementParser
{
    private const REQUIRED_COLUMNS = ['reference', 'amount_minor_units', 'currency', 'statement_date'];

    /** @return StatementLine[] */
    public function parse(string $csvContents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContents));

        if ($lines === false || $lines === ['']) {
            throw new MalformedStatementException(['The CSV file is empty.']);
        }

        $header = str_getcsv(array_shift($lines));
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $header));

        if ($missingColumns !== []) {
            throw new MalformedStatementException(array_map(
                static fn (string $column) => "Missing required column: {$column}",
                $missingColumns,
            ));
        }

        $columnIndex = array_flip($header);
        $statementLines = [];

        foreach ($lines as $index => $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }

            $fields = str_getcsv($rawLine);

            $statementLines[] = new StatementLine(
                rowNumber: $index + 1,
                reference: $fields[$columnIndex['reference']] ?? '',
                amountMinorUnits: $fields[$columnIndex['amount_minor_units']] ?? '',
                currency: $fields[$columnIndex['currency']] ?? '',
                statementDate: $fields[$columnIndex['statement_date']] ?? '',
                rawLine: $rawLine,
            );
        }

        return $statementLines;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CsvStatementParserTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/StatementLine.php app/Modules/Reconciliation/Infrastructure/MalformedStatementException.php app/Modules/Reconciliation/Infrastructure/CsvStatementParser.php tests/Feature/Modules/Reconciliation/CsvStatementParserTest.php
git commit -m "feat(reconciliation): add CsvStatementParser (ADR-005)"
```

---

## Task 20: Reconciliation Infrastructure — regole di validazione e StatementRowValidator

**Files:**
- Create: `app/Modules/Reconciliation/Infrastructure/Rules/ValidMoneyAmountRule.php`
- Create: `app/Modules/Reconciliation/Infrastructure/Rules/ValidCurrencyRule.php`
- Create: `app/Modules/Reconciliation/Application/ImportStatementRow.php`
- Create: `app/Modules/Reconciliation/Infrastructure/StatementRowValidator.php`
- Test: `tests/Feature/Modules/Reconciliation/StatementRowValidatorTest.php`

Regole di validazione Laravel custom, come deciso nel brainstorming (nessuna libreria di terze parti). Formato data CSV: ISO-8601 `YYYY-MM-DD` (decisione di implementazione, vedi header del piano).

**Interfaces:**
- Consumes: `StatementLine` (Task 19), `Currency` (SharedKernel).
- Produces: `ImportStatementRow` (readonly DTO: `$rowNumber`, `$reference`, `$amountMinorUnits` (int), `$currency` (`Currency`), `$statementDate` (`DateTimeImmutable`), `$rawLine`), `StatementRowValidator::validate(StatementLine $line): array{0: ?ImportStatementRow, 1: string[]}`. Consumato da `ImportStatementService` (Task 21).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Infrastructure\StatementLine;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\SharedKernel\Domain\Currency;

it('validates and normalizes a well-formed row', function () {
    $line = new StatementLine(1, ' REF-1 ', '12345', 'eur', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($errors)->toBe([])
        ->and($row->reference)->toBe('REF-1')
        ->and($row->amountMinorUnits)->toBe(12345)
        ->and($row->currency)->toBe(Currency::EUR)
        ->and($row->statementDate->format('Y-m-d'))->toBe('2026-07-31');
});

it('rejects a non-numeric amount', function () {
    $line = new StatementLine(1, 'REF-1', 'not-a-number', 'EUR', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects an unsupported currency code', function () {
    $line = new StatementLine(1, 'REF-1', '12345', 'XXX', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects a malformed date', function () {
    $line = new StatementLine(1, 'REF-1', '12345', 'EUR', '31/07/2026', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});

it('rejects a missing reference', function () {
    $line = new StatementLine(1, '', '12345', 'EUR', '2026-07-31', 'raw');

    [$row, $errors] = (new StatementRowValidator())->validate($line);

    expect($row)->toBeNull()->and($errors)->not->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatementRowValidatorTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidMoneyAmountRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '' || !preg_match('/^\d+$/', $value)) {
            $fail('The :attribute must be a non-negative integer number of minor units.');
        }
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Rules;

use App\Modules\SharedKernel\Domain\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCurrencyRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || Currency::tryFrom(strtoupper($value)) === null) {
            $fail('The :attribute must be a supported ISO 4217 currency code.');
        }
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\SharedKernel\Domain\Currency;
use DateTimeImmutable;

final class ImportStatementRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $reference,
        public readonly int $amountMinorUnits,
        public readonly Currency $currency,
        public readonly DateTimeImmutable $statementDate,
        public readonly string $rawLine,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Application\ImportStatementRow;
use App\Modules\Reconciliation\Infrastructure\Rules\ValidCurrencyRule;
use App\Modules\Reconciliation\Infrastructure\Rules\ValidMoneyAmountRule;
use App\Modules\SharedKernel\Domain\Currency;
use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;

final class StatementRowValidator
{
    /** @return array{0: ?ImportStatementRow, 1: string[]} */
    public function validate(StatementLine $line): array
    {
        $validator = Validator::make(
            [
                'reference' => $line->reference,
                'amount_minor_units' => $line->amountMinorUnits,
                'currency' => $line->currency,
                'statement_date' => $line->statementDate,
            ],
            [
                'reference' => ['required', 'string'],
                'amount_minor_units' => ['required', new ValidMoneyAmountRule()],
                'currency' => ['required', new ValidCurrencyRule()],
                'statement_date' => ['required', 'date_format:Y-m-d'],
            ],
        );

        if ($validator->fails()) {
            return [null, $validator->errors()->all()];
        }

        $row = new ImportStatementRow(
            rowNumber: $line->rowNumber,
            reference: trim($line->reference),
            amountMinorUnits: (int) $line->amountMinorUnits,
            currency: Currency::from(strtoupper($line->currency)),
            statementDate: DateTimeImmutable::createFromFormat('Y-m-d', $line->statementDate),
            rawLine: $line->rawLine,
        );

        return [$row, []];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StatementRowValidatorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/Rules/ app/Modules/Reconciliation/Application/ImportStatementRow.php app/Modules/Reconciliation/Infrastructure/StatementRowValidator.php tests/Feature/Modules/Reconciliation/StatementRowValidatorTest.php
git commit -m "feat(reconciliation): add CSV row validation rules and StatementRowValidator"
```

---

## Task 21: Reconciliation Application — ImportStatementService

**Files:**
- Create: `app/Modules/Reconciliation/Infrastructure/MatchPendingTransactionJob.php` (stub — solo la firma del costruttore, per rendere l'`ImportStatementService` compilabile; l'implementazione completa è nel Task 22)
- Create: `app/Modules/Reconciliation/Application/ImportSummary.php`
- Create: `app/Modules/Reconciliation/Application/ImportStatementService.php`
- Test: `tests/Feature/Modules/Reconciliation/ImportStatementServiceTest.php`

Orchestrazione dello spec §6.1: raggruppa le righe valide per `(reference, amount_minor_units, currency, statement_date)`, deriva `occurrence_index` all'interno di ciascun gruppo (ADR-007), tenta sempre l'append senza controllare prima l'esistenza (ADR-006), tratta un conflitto come no-op.

**Interfaces:**
- Consumes: `CsvStatementParser` (Task 19), `StatementRowValidator`, `ImportStatementRow` (Task 20), `Transaction` (Task 12–14), `TransactionRepository` (Task 17), `TransactionReadModelProjector` (Task 18), `IdempotencyKey`, `TransactionId`, `Money`, `Actor`, `ConcurrencyConflictException` (SharedKernel).
- Produces: `ImportSummary` (readonly: `$rowsReceived`, `$rowsImported`, `$rowsAlreadyImported`, `$rowsInvalid`, `$invalidRows`, `$transactionIds`), `ImportStatementService::import(string $csvContents, Actor $actor, string $correlationId): ImportSummary`. Consumato da `ImportsController` (Task 25).

- [ ] **Step 1: Write the job stub**

`MatchPendingTransactionJob` viene completato nel Task 22; qui basta la firma statica `dispatch()` fornita dal trait `Dispatchable`, così `ImportStatementService` può dispatchare il job senza dipendere da un'implementazione ancora incompleta.

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MatchPendingTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $correlationId,
    ) {
    }

    public function handle(): void
    {
        // Completato nel Task 22.
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MalformedStatementException;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function importService(): ImportStatementService
{
    return new ImportStatementService(
        new CsvStatementParser(),
        new StatementRowValidator(),
        new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())),
        new TransactionReadModelProjector(),
    );
}

const VALID_STATEMENT_CSV = <<<CSV
reference,amount_minor_units,currency,statement_date
REF-1,12345,EUR,2026-07-31
REF-2,500,EUR,2026-07-31
CSV;

it('imports every valid row and dispatches a matching job for each', function () {
    Queue::fake();

    $summary = importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsReceived)->toBe(2)
        ->and($summary->rowsImported)->toBe(2)
        ->and($summary->rowsAlreadyImported)->toBe(0)
        ->and($summary->rowsInvalid)->toBe(0)
        ->and($summary->transactionIds)->toHaveCount(2);

    Queue::assertPushed(MatchPendingTransactionJob::class, 2);
});

it('is idempotent: re-importing the same statement imports nothing new', function () {
    Queue::fake();

    importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());
    $second = importService()->import(VALID_STATEMENT_CSV, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($second->rowsImported)->toBe(0)
        ->and($second->rowsAlreadyImported)->toBe(2);
});

it('imports two genuinely identical rows as two distinct transactions, and a resubmission of both as a no-op', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-1,12345,EUR,2026-07-31";

    $summary = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsImported)->toBe(2)
        ->and($summary->transactionIds[0])->not->toBe($summary->transactionIds[1]);

    $resubmitted = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());
    expect($resubmitted->rowsImported)->toBe(0)
        ->and($resubmitted->rowsAlreadyImported)->toBe(2);
});

it('reports content-invalid rows without failing the rest of the import', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,not-a-number,EUR,2026-07-31";

    $summary = importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid());

    expect($summary->rowsImported)->toBe(1)
        ->and($summary->rowsInvalid)->toBe(1)
        ->and($summary->invalidRows[0]['row_number'])->toBe(2);
});

it('throws for a structurally invalid CSV', function () {
    $csv = "reference,amount_minor_units\nREF-1,12345";

    expect(fn () => importService()->import($csv, Actor::apiCaller('caller-1'), (string) Str::uuid()))
        ->toThrow(MalformedStatementException::class);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=ImportStatementServiceTest`
Expected: FAIL — class `ImportStatementService`/`ImportSummary` not found.

- [ ] **Step 4: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Application;

final class ImportSummary
{
    /**
     * @param string[] $transactionIds
     * @param array<int, array{row_number: int, errors: string[]}> $invalidRows
     */
    public function __construct(
        public readonly int $rowsReceived,
        public readonly int $rowsImported,
        public readonly int $rowsAlreadyImported,
        public readonly int $rowsInvalid,
        public readonly array $invalidRows,
        public readonly array $transactionIds,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\CsvStatementParser;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\Reconciliation\Infrastructure\StatementRowValidator;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Support\Str;

final class ImportStatementService
{
    public function __construct(
        private readonly CsvStatementParser $parser,
        private readonly StatementRowValidator $rowValidator,
        private readonly TransactionRepository $repository,
        private readonly TransactionReadModelProjector $projector,
    ) {
    }

    public function import(string $csvContents, Actor $actor, string $correlationId): ImportSummary
    {
        $lines = $this->parser->parse($csvContents);

        $validRows = [];
        $invalidRows = [];

        foreach ($lines as $line) {
            [$row, $errors] = $this->rowValidator->validate($line);

            if ($row === null) {
                $invalidRows[] = ['row_number' => $line->rowNumber, 'errors' => $errors];
                continue;
            }

            $validRows[] = $row;
        }

        $groups = [];
        foreach ($validRows as $row) {
            $groupKey = implode('|', [
                $row->reference,
                $row->amountMinorUnits,
                $row->currency->value,
                $row->statementDate->format('Y-m-d'),
            ]);
            $groups[$groupKey][] = $row;
        }

        $importedIds = [];
        $alreadyImportedCount = 0;

        foreach ($groups as $rowsInGroup) {
            foreach (array_values($rowsInGroup) as $occurrenceIndex => $row) {
                $idempotencyKey = IdempotencyKey::forStatementRow(
                    $row->reference,
                    $row->amountMinorUnits,
                    $row->currency,
                    $row->statementDate,
                    $occurrenceIndex,
                );
                $transactionId = TransactionId::deriveFrom($idempotencyKey);

                $transaction = Transaction::import(
                    id: $transactionId,
                    money: new Money($row->amountMinorUnits, $row->currency),
                    reference: $row->reference,
                    statementDate: $row->statementDate,
                    occurrenceIndex: $occurrenceIndex,
                    idempotencyKey: $idempotencyKey,
                    rawRowChecksum: hash('sha256', $row->rawLine),
                    actor: $actor,
                    causationId: (string) Str::uuid(),
                    correlationId: $correlationId,
                );

                try {
                    $this->repository->save($transaction);
                } catch (ConcurrencyConflictException) {
                    $alreadyImportedCount++;
                    continue;
                }

                $this->projector->project($transaction);
                $importedIds[] = $transactionId->value;

                MatchPendingTransactionJob::dispatch($transactionId->value, $correlationId);
            }
        }

        return new ImportSummary(
            rowsReceived: count($lines),
            rowsImported: count($importedIds),
            rowsAlreadyImported: $alreadyImportedCount,
            rowsInvalid: count($invalidRows),
            invalidRows: $invalidRows,
            transactionIds: $importedIds,
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ImportStatementServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/MatchPendingTransactionJob.php app/Modules/Reconciliation/Application/ImportSummary.php app/Modules/Reconciliation/Application/ImportStatementService.php tests/Feature/Modules/Reconciliation/ImportStatementServiceTest.php
git commit -m "feat(reconciliation): add ImportStatementService (spec §6.1, ADR-006, ADR-007)"
```

---

## Task 22: Reconciliation Infrastructure — MatchPendingTransactionJob (implementazione completa)

**Files:**
- Modify: `app/Modules/Reconciliation/Infrastructure/MatchPendingTransactionJob.php`
- Test: `tests/Feature/Modules/Reconciliation/MatchPendingTransactionJobTest.php`

Implementa spec §6.2 e §8 ("Queue retry"): carica la transaction, no-op se non è più `Pending` (redelivery sicura), altrimenti decide e salva.

**Interfaces:**
- Consumes: `TransactionRepository` (Task 17), `MatchTransactionService` (Task 16), `TransactionReadModelProjector` (Task 18), `TransactionState` (Task 10), `TransactionId`, `Actor` (SharedKernel).
- Produces: `MatchPendingTransactionJob::handle(TransactionRepository $repository, MatchTransactionService $matcher, TransactionReadModelProjector $projector): void` — risolto dal container di Laravel (queued job, dependency injection sul metodo `handle`). Dispatchato da `ImportStatementService` (Task 21).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\Reconciliation\Infrastructure\MatchPendingTransactionJob;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function importedPendingTransaction(): TransactionId
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );

    app(TransactionRepository::class)->save($transaction);

    return $id;
}

it('matches a Pending transaction and projects the outcome', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $id = importedPendingTransaction();

    (new MatchPendingTransactionJob($id->value, 'r1'))->handle(
        app(TransactionRepository::class),
        app(\App\Modules\Reconciliation\Application\MatchTransactionService::class),
        app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class),
    );

    $found = app(TransactionRepository::class)->find($id);
    expect($found->state())->toBe(TransactionState::Reconciled);

    $projected = \App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection::query()->find($id->value);
    expect($projected->state)->toBe('Reconciled');
});

it('is a no-op when the transaction is no longer Pending (queue redelivery)', function () {
    $id = importedPendingTransaction();
    $repository = app(TransactionRepository::class);
    $matcher = app(\App\Modules\Reconciliation\Application\MatchTransactionService::class);
    $projector = app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class);

    (new MatchPendingTransactionJob($id->value, 'r1'))->handle($repository, $matcher, $projector);
    $afterFirstRun = $repository->find($id)->version();

    (new MatchPendingTransactionJob($id->value, 'r1'))->handle($repository, $matcher, $projector);
    $afterSecondRun = $repository->find($id)->version();

    expect($afterSecondRun)->toBe($afterFirstRun);
});

it('is a no-op for an unknown transaction id', function () {
    $repository = app(TransactionRepository::class);
    $matcher = app(\App\Modules\Reconciliation\Application\MatchTransactionService::class);
    $projector = app(\App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector::class);

    $unknownId = (string) Str::uuid();
    (new MatchPendingTransactionJob($unknownId, 'r1'))->handle($repository, $matcher, $projector);

    expect(\App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection::query()->find($unknownId))->toBeNull();
});
```

Questo test presume che `TransactionRepository`, `MatchTransactionService` e `TransactionReadModelProjector` siano risolvibili dal container (`app(...)`). `TransactionRepository` richiede un `EventStore` — verrà bindato nel Task 24. Per far passare questo test *prima* di quel binding, il service provider minimo va registrato ora:

- [ ] **Step 2: Register a minimal EventStore binding**

Crea `app/Providers/ReconciliationServiceProvider.php` (completato ulteriormente nel Task 24):

```php
<?php

namespace App\Providers;

use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Support\ServiceProvider;

final class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventStore::class, function () {
            return new PostgresEventStore(TransactionEventTypes::map());
        });
    }
}
```

Registra il provider in `bootstrap/providers.php`, aggiungendo `App\Providers\ReconciliationServiceProvider::class` all'array restituito.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=MatchPendingTransactionJobTest`
Expected: FAIL — `handle()` non fa ancora nulla (Task 21 lo ha lasciato vuoto).

- [ ] **Step 4: Write minimal implementation**

Sostituisci il corpo di `MatchPendingTransactionJob.php`:

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure;

use App\Modules\Reconciliation\Application\MatchTransactionService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class MatchPendingTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $correlationId,
    ) {
    }

    public function handle(
        TransactionRepository $repository,
        MatchTransactionService $matcher,
        TransactionReadModelProjector $projector,
    ): void {
        $transaction = $repository->find(TransactionId::fromString($this->transactionId));

        if ($transaction === null || $transaction->state() !== TransactionState::Pending) {
            return;
        }

        $matcher->match($transaction, Actor::system(), (string) Str::uuid(), $this->correlationId);

        $repository->save($transaction);
        $projector->project($transaction);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=MatchPendingTransactionJobTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/MatchPendingTransactionJob.php app/Providers/ReconciliationServiceProvider.php bootstrap/providers.php tests/Feature/Modules/Reconciliation/MatchPendingTransactionJobTest.php
git commit -m "feat(reconciliation): complete MatchPendingTransactionJob (spec §6.2, §8 queue retry)"
```

---

## Task 23: Reconciliation Application — ResolveReviewService

**Files:**
- Create: `app/Modules/Reconciliation/Application/ResolveReviewService.php`
- Test: `tests/Feature/Modules/Reconciliation/ResolveReviewServiceTest.php`

Implementa spec §6.3: risoluzione manuale di una transazione `NeedsReview`. Genera un `correlation_id` e `causation_id` propri (processo separato dall'import, per decisione di implementazione — vedi header del piano).

**Interfaces:**
- Consumes: `TransactionRepository` (Task 17), `TransactionReadModelProjector` (Task 18), `TransactionId`, `Actor` (SharedKernel), `TransactionNotFound` (Task 10).
- Produces: `ResolveReviewService::confirm(TransactionId $id, string $expectedPaymentId, Actor $actor): Transaction`, `reject(TransactionId $id, string $reason, Actor $actor): Transaction`. Consumato da `ResolveTransactionController` (Task 27).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\ResolveReviewService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
use App\Modules\Reconciliation\Domain\Exceptions\TransactionNotFound;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Domain\TransactionState;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\Money;
use App\Modules\SharedKernel\Domain\TransactionId;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function needsReviewTransactionId(): TransactionId
{
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    $transaction = Transaction::import(
        id: $id, money: new Money(12345, Currency::EUR), reference: 'REF-1',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-1',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    $transaction->markAmbiguous(['ep-1', 'ep-2'], 'multiple_candidates', Actor::system(), 'c2', 'r1');

    $repository = new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));
    $repository->save($transaction);

    return $id;
}

function resolveService(): ResolveReviewService
{
    return new ResolveReviewService(
        new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())),
        new TransactionReadModelProjector(),
    );
}

it('confirms a NeedsReview transaction against a recorded candidate', function () {
    $id = needsReviewTransactionId();

    $transaction = resolveService()->confirm($id, 'ep-1', Actor::apiCaller('reviewer-1'));

    expect($transaction->state())->toBe(TransactionState::Reconciled)
        ->and($transaction->matchedExpectedPaymentId())->toBe('ep-1');
});

it('rejects a NeedsReview transaction with a reason', function () {
    $id = needsReviewTransactionId();

    $transaction = resolveService()->reject($id, 'not our payment', Actor::apiCaller('reviewer-1'));

    expect($transaction->state())->toBe(TransactionState::Rejected);
});

it('throws TransactionNotFound for an unknown id', function () {
    expect(fn () => resolveService()->confirm(TransactionId::fromString((string) Str::uuid()), 'ep-1', Actor::apiCaller('reviewer-1')))
        ->toThrow(TransactionNotFound::class);
});

it('throws InvalidResolutionCandidate for a candidate that was never recorded', function () {
    $id = needsReviewTransactionId();

    expect(fn () => resolveService()->confirm($id, 'ep-not-a-candidate', Actor::apiCaller('reviewer-1')))
        ->toThrow(InvalidResolutionCandidate::class);
});

it('throws IllegalTransactionStateTransition when the transaction is not NeedsReview', function () {
    $key = IdempotencyKey::forStatementRow('REF-2', 500, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);
    $transaction = Transaction::import(
        id: $id, money: new Money(500, Currency::EUR), reference: 'REF-2',
        statementDate: new DateTimeImmutable('2026-07-31'), occurrenceIndex: 0,
        idempotencyKey: $key, rawRowChecksum: 'checksum-2',
        actor: Actor::apiCaller('caller-1'), causationId: 'c1', correlationId: 'r1',
    );
    (new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map())))->save($transaction);

    expect(fn () => resolveService()->confirm($id, 'ep-1', Actor::apiCaller('reviewer-1')))
        ->toThrow(IllegalTransactionStateTransition::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ResolveReviewServiceTest`
Expected: FAIL — class `ResolveReviewService` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Application;

use App\Modules\Reconciliation\Domain\Exceptions\TransactionNotFound;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\TransactionReadModelProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Support\Str;

final class ResolveReviewService
{
    public function __construct(
        private readonly TransactionRepository $repository,
        private readonly TransactionReadModelProjector $projector,
    ) {
    }

    public function confirm(TransactionId $id, string $expectedPaymentId, Actor $actor): Transaction
    {
        $transaction = $this->load($id);

        $transaction->resolveByConfirming($expectedPaymentId, $actor, (string) Str::uuid(), (string) Str::uuid());

        $this->repository->save($transaction);
        $this->projector->project($transaction);

        return $transaction;
    }

    public function reject(TransactionId $id, string $reason, Actor $actor): Transaction
    {
        $transaction = $this->load($id);

        $transaction->resolveByRejecting($reason, $actor, (string) Str::uuid(), (string) Str::uuid());

        $this->repository->save($transaction);
        $this->projector->project($transaction);

        return $transaction;
    }

    private function load(TransactionId $id): Transaction
    {
        return $this->repository->find($id) ?? throw new TransactionNotFound($id->value);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ResolveReviewServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Application/ResolveReviewService.php tests/Feature/Modules/Reconciliation/ResolveReviewServiceTest.php
git commit -m "feat(reconciliation): add ResolveReviewService (spec §6.3)"
```

---

## Task 24: Registrazione route API e verifica del provider

**Files:**
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Modules/Reconciliation/ApiRoutingSmokeTest.php`

Il binding di `EventStore` è già registrato nel Task 22 (`ReconciliationServiceProvider`). Qui si collega `routes/api.php` al bootstrap dell'applicazione (Laravel 13 configura il routing in `bootstrap/app.php`, non in un `RouteServiceProvider` separato) e si verifica che l'intero grafo delle dipendenze si risolva dal container end-to-end.

**Interfaces:**
- Nessuna nuova interfaccia — collega il routing esistente ai controller che verranno creati nei Task 25–27.

- [ ] **Step 1: Create the routes file**

```php
<?php

use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ImportsController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\ResolveTransactionController;
use App\Modules\Reconciliation\Infrastructure\Http\Controllers\TransactionsController;
use Illuminate\Support\Facades\Route;

Route::post('/imports', [ImportsController::class, 'store']);
Route::get('/transactions', [TransactionsController::class, 'index']);
Route::get('/transactions/{id}', [TransactionsController::class, 'show']);
Route::post('/transactions/{id}/resolve', [ResolveTransactionController::class, 'store']);
```

- [ ] **Step 2: Register the routes file in `bootstrap/app.php`**

Modifica la chiamata a `->withRouting(...)` aggiungendo la riga `api:`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

- [ ] **Step 3: Write the failing smoke test**

I controller referenziati nel file di route non esistono ancora (arrivano nei Task 25–27): questo test verifica solo che il file di route sia caricato e che una rotta sconosciuta risponda `404`, non `500` per un errore di bootstrap. Verrà esteso nei task successivi con assert reali sulle rotte.

```php
<?php

it('boots the application with the api routes file loaded', function () {
    $response = $this->getJson('/api/this-route-does-not-exist');

    $response->assertStatus(404);
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --filter=ApiRoutingSmokeTest`
Expected: FAIL — errore di bootstrap (classi controller non trovate), non un semplice 404.

- [ ] **Step 5: Verify — this step is satisfied automatically once Task 25–27 create the controllers**

Per ora, per isolare questo task, crea gli stub minimi dei tre controller (rimpiazzati per intero nei Task 25–27):

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;

final class ImportsController extends Controller
{
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;

final class TransactionsController extends Controller
{
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use Illuminate\Routing\Controller;

final class ResolveTransactionController extends Controller
{
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ApiRoutingSmokeTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add routes/api.php bootstrap/app.php app/Modules/Reconciliation/Infrastructure/Http/Controllers/ tests/Feature/Modules/Reconciliation/ApiRoutingSmokeTest.php
git commit -m "chore(reconciliation): wire up api routes and controller stubs"
```

---

## Task 25: HTTP — ImportStatementRequest e ImportsController

**Files:**
- Create: `app/Modules/Reconciliation/Infrastructure/Http/Requests/ImportStatementRequest.php`
- Modify: `app/Modules/Reconciliation/Infrastructure/Http/Controllers/ImportsController.php`
- Test: `tests/Feature/Modules/Reconciliation/ImportsEndpointTest.php`

Implementa `POST /imports` come da technical design §4, con l'estensione decisa in questo piano (`rows_invalid`, `invalid_rows`). L'identità del chiamante è auto-dichiarata via header `X-Actor-Id` (ADR-008).

**Interfaces:**
- Consumes: `ImportStatementService` (Task 21).
- Produces: endpoint `POST /imports`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('imports a valid CSV statement and reports a correlation id', function () {
    Queue::fake();

    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,500,EUR,2026-07-31";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $response = $this->postJson('/api/imports', ['file' => $file], ['X-Actor-Id' => 'caller-1']);

    $response->assertOk()
        ->assertJsonPath('rows_received', 2)
        ->assertJsonPath('rows_imported', 2)
        ->assertJsonPath('rows_already_imported', 0)
        ->assertJsonPath('rows_invalid', 0)
        ->assertJsonStructure(['correlation_id', 'transaction_ids']);

    expect(TransactionProjection::query()->count())->toBe(2);
});

it('returns 422 for a structurally invalid CSV', function () {
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units\nREF-1,12345");

    $response = $this->postJson('/api/imports', ['file' => $file]);

    $response->assertStatus(422)->assertJsonStructure(['errors']);
});

it('returns 422 when no file is provided', function () {
    $response = $this->postJson('/api/imports', []);

    $response->assertStatus(422);
});

it('reports content-invalid rows in the response without failing the request', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,not-a-number,EUR,2026-07-31";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $response = $this->postJson('/api/imports', ['file' => $file]);

    $response->assertOk()
        ->assertJsonPath('rows_imported', 1)
        ->assertJsonPath('rows_invalid', 1);
});

it('is idempotent over HTTP: resubmitting the same statement imports nothing new', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";

    $file1 = UploadedFile::fake()->createWithContent('statement.csv', $csv);
    $this->postJson('/api/imports', ['file' => $file1])->assertOk();

    $file2 = UploadedFile::fake()->createWithContent('statement.csv', $csv);
    $response = $this->postJson('/api/imports', ['file' => $file2]);

    $response->assertJsonPath('rows_imported', 0)->assertJsonPath('rows_already_imported', 1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImportsEndpointTest`
Expected: FAIL — `ImportsController::store` non esiste ancora.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ];
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ImportStatementRequest;
use App\Modules\Reconciliation\Infrastructure\MalformedStatementException;
use App\Modules\SharedKernel\Domain\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ImportsController extends Controller
{
    public function __construct(private readonly ImportStatementService $service)
    {
    }

    public function store(ImportStatementRequest $request): JsonResponse
    {
        $csvContents = $request->file('file')->get();
        $actor = Actor::apiCaller($request->header('X-Actor-Id', 'unknown'));
        $correlationId = (string) Str::uuid();

        try {
            $summary = $this->service->import($csvContents, $actor, $correlationId);
        } catch (MalformedStatementException $e) {
            return response()->json(['errors' => $e->errors], 422);
        }

        return response()->json([
            'correlation_id' => $correlationId,
            'rows_received' => $summary->rowsReceived,
            'rows_imported' => $summary->rowsImported,
            'rows_already_imported' => $summary->rowsAlreadyImported,
            'rows_invalid' => $summary->rowsInvalid,
            'invalid_rows' => $summary->invalidRows,
            'transaction_ids' => $summary->transactionIds,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ImportsEndpointTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/Http/Requests/ImportStatementRequest.php app/Modules/Reconciliation/Infrastructure/Http/Controllers/ImportsController.php tests/Feature/Modules/Reconciliation/ImportsEndpointTest.php
git commit -m "feat(reconciliation): add POST /imports endpoint (technical design §4)"
```

---

## Task 26: HTTP — TransactionsController (`index`, `show`)

**Files:**
- Modify: `app/Modules/Reconciliation/Infrastructure/Http/Controllers/TransactionsController.php`
- Test: `tests/Feature/Modules/Reconciliation/TransactionsEndpointTest.php`

`GET /transactions/{id}` ricostruisce l'aggregate dallo stream di eventi e restituisce anche la storia completa — questo endpoint *è* la vista dell'audit trail (spec §7).

**Interfaces:**
- Consumes: `TransactionProjection` (Task 18), `EventStore` (Task 9), `Transaction::reconstituteFromStream()` (Task 7, 12–14).
- Produces: endpoint `GET /transactions`, `GET /transactions/{id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('lists transactions, optionally filtered by state', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31\nREF-2,500,EUR,2026-07-31";
    app(ImportStatementService::class)->import($csv, \App\Modules\SharedKernel\Domain\Actor::system(), (string) Str::uuid());

    $response = $this->getJson('/api/transactions');
    $response->assertOk()->assertJsonCount(2, 'data');

    $filtered = $this->getJson('/api/transactions?state=Pending');
    $filtered->assertOk()->assertJsonCount(2, 'data');

    $none = $this->getJson('/api/transactions?state=Reconciled');
    $none->assertOk()->assertJsonCount(0, 'data');
});

it('shows a transaction with its full event history', function () {
    Queue::fake();
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, \App\Modules\SharedKernel\Domain\Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0];

    $response = $this->getJson("/api/transactions/{$id}");

    $response->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('state', 'Pending')
        ->assertJsonPath('history.0.event_type', 'transaction.imported')
        ->assertJsonCount(1, 'history');
});

it('returns 404 for an unknown transaction id', function () {
    $response = $this->getJson('/api/transactions/' . (string) Str::uuid());

    $response->assertStatus(404);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TransactionsEndpointTest`
Expected: FAIL — `TransactionsController::index`/`show` non esistono ancora.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\DomainEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TransactionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TransactionProjection::query();

        if ($request->filled('state')) {
            $query->where('state', $request->string('state'));
        }

        $data = $query->orderBy('imported_at')->get()->map(fn (TransactionProjection $t) => [
            'id' => $t->transaction_id,
            'state' => $t->state,
            'amount_minor_units' => $t->amount_minor_units,
            'currency' => $t->currency,
            'reference' => $t->reference,
            'statement_date' => $t->statement_date->format('Y-m-d'),
            'imported_at' => $t->imported_at->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    public function show(string $id, EventStore $eventStore): JsonResponse
    {
        $events = $eventStore->loadStream($id);

        if ($events === []) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $transaction = Transaction::reconstituteFromStream($events);

        return response()->json($this->toDetailPayload($transaction, $events));
    }

    /** @param DomainEvent[] $events */
    private function toDetailPayload(Transaction $transaction, array $events): array
    {
        return [
            'id' => $transaction->aggregateId(),
            'state' => $transaction->state()->value,
            'amount_minor_units' => $transaction->money()->amountMinorUnits,
            'currency' => $transaction->money()->currency->value,
            'reference' => $transaction->reference(),
            'version' => $transaction->version(),
            'history' => array_map(fn (DomainEvent $event) => [
                'event_type' => $event->eventType(),
                'occurred_at' => $event->occurredAt()->format(DATE_ATOM),
                'actor' => ['type' => $event->actor()->type->value, 'id' => $event->actor()->id],
                'causation_id' => $event->causationId(),
                'correlation_id' => $event->correlationId(),
                'payload' => $event->payload(),
            ], $events),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransactionsEndpointTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/Http/Controllers/TransactionsController.php tests/Feature/Modules/Reconciliation/TransactionsEndpointTest.php
git commit -m "feat(reconciliation): add GET /transactions and GET /transactions/{id} endpoints (technical design §4)"
```

---

## Task 27: HTTP — ResolveTransactionRequest e ResolveTransactionController

**Files:**
- Create: `app/Modules/Reconciliation/Infrastructure/Http/Requests/ResolveTransactionRequest.php`
- Modify: `app/Modules/Reconciliation/Infrastructure/Http/Controllers/ResolveTransactionController.php`
- Test: `tests/Feature/Modules/Reconciliation/ResolveTransactionEndpointTest.php`

Implementa `POST /transactions/{id}/resolve` come da technical design §4: `409` per stato illegale o conflitto di concorrenza, `422` per candidato non valido o motivo di rifiuto mancante (quest'ultimo verificato direttamente dalla `FormRequest`).

**Interfaces:**
- Consumes: `ResolveReviewService` (Task 23), `EventStore` (per costruire il body del 409 con lo stato corrente).
- Produces: endpoint `POST /transactions/{id}/resolve`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Reconciliation\Application\ImportStatementService;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\SharedKernel\Domain\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function importAndReturnNeedsReviewId(): string
{
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $csv = "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0];

    // Il job di matching gira sync in test (QUEUE_CONNECTION=sync in .env.testing).
    return $id;
}

it('confirms a NeedsReview transaction against a valid candidate', function () {
    $id = importAndReturnNeedsReviewId();
    $candidateId = ExpectedPayment::query()->where('reference', 'REF-1')->where('amount_minor_units', 12345)->first()->id;

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'confirm',
        'expected_payment_id' => $candidateId,
    ]);

    $response->assertOk()->assertJsonPath('state', 'Reconciled');
});

it('rejects a NeedsReview transaction with a reason', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'reject',
        'reason' => 'not our payment',
    ]);

    $response->assertOk()->assertJsonPath('state', 'Rejected');
});

it('returns 422 when rejecting without a reason', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", ['action' => 'reject']);

    $response->assertStatus(422);
});

it('returns 422 for a candidate that was never recorded', function () {
    $id = importAndReturnNeedsReviewId();

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'confirm',
        'expected_payment_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(422);
});

it('returns 409 when the transaction is not currently NeedsReview', function () {
    $csv = "reference,amount_minor_units,currency,statement_date\nREF-NOMATCH,12345,EUR,2026-07-31";
    $summary = app(ImportStatementService::class)->import($csv, Actor::system(), (string) Str::uuid());
    $id = $summary->transactionIds[0]; // Unmatched, non NeedsReview

    $response = $this->postJson("/api/transactions/{$id}/resolve", [
        'action' => 'reject',
        'reason' => 'irrelevant',
    ]);

    $response->assertStatus(409)->assertJsonStructure(['message', 'current_state']);
});

it('returns 404 for an unknown transaction id', function () {
    $response = $this->postJson('/api/transactions/' . (string) Str::uuid() . '/resolve', [
        'action' => 'reject',
        'reason' => 'irrelevant',
    ]);

    $response->assertStatus(404);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ResolveTransactionEndpointTest`
Expected: FAIL — `ResolveTransactionController::store` non esiste ancora.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:confirm,reject'],
            'expected_payment_id' => ['required_if:action,confirm', 'uuid'],
            'reason' => ['required_if:action,reject', 'string'],
        ];
    }
}
```

```php
<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\ResolveReviewService;
use App\Modules\Reconciliation\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Reconciliation\Domain\Exceptions\InvalidResolutionCandidate;
use App\Modules\Reconciliation\Domain\Exceptions\TransactionNotFound;
use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ResolveTransactionRequest;
use App\Modules\SharedKernel\Application\EventStore;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Domain\TransactionId;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ResolveTransactionController extends Controller
{
    public function __construct(
        private readonly ResolveReviewService $service,
        private readonly EventStore $eventStore,
    ) {
    }

    public function store(string $id, ResolveTransactionRequest $request): JsonResponse
    {
        $transactionId = TransactionId::fromString($id);
        $actor = Actor::apiCaller($request->header('X-Actor-Id', 'unknown'));

        try {
            $transaction = $request->string('action') === 'confirm'
                ? $this->service->confirm($transactionId, (string) $request->string('expected_payment_id'), $actor)
                : $this->service->reject($transactionId, (string) $request->string('reason'), $actor);
        } catch (TransactionNotFound) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        } catch (IllegalTransactionStateTransition $e) {
            return response()->json(['message' => 'Transaction is not currently resolvable.', 'current_state' => $e->currentState->value], 409);
        } catch (ConcurrencyConflictException) {
            return $this->currentStateConflictResponse($id);
        } catch (InvalidResolutionCandidate $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->toDetailPayload($transaction));
    }

    private function currentStateConflictResponse(string $id): JsonResponse
    {
        $transaction = Transaction::reconstituteFromStream($this->eventStore->loadStream($id));

        return response()->json([
            'message' => 'Transaction is not currently resolvable.',
            'current_state' => $transaction->state()->value,
        ], 409);
    }

    private function toDetailPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->aggregateId(),
            'state' => $transaction->state()->value,
            'amount_minor_units' => $transaction->money()->amountMinorUnits,
            'currency' => $transaction->money()->currency->value,
            'reference' => $transaction->reference(),
            'version' => $transaction->version(),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ResolveTransactionEndpointTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reconciliation/Infrastructure/Http/Requests/ResolveTransactionRequest.php app/Modules/Reconciliation/Infrastructure/Http/Controllers/ResolveTransactionController.php tests/Feature/Modules/Reconciliation/ResolveTransactionEndpointTest.php
git commit -m "feat(reconciliation): add POST /transactions/{id}/resolve endpoint (technical design §4)"
```

---

## Task 28: Test end-to-end — percorso completo via API e concorrenza applicativa

**Files:**
- Create: `tests/Feature/Modules/Reconciliation/EndToEndReconciliationTest.php`

Copre gli ultimi due punti dello spec §10 non ancora esercitati end-to-end: "Feature tests: full path from CSV import through matching to a reconciled or rejected transaction, via the API" e "Concurrency tests: simulate a version conflict on append and assert the retry/conflict behavior" — qui a livello applicativo (due caricamenti indipendenti dello stesso aggregate che divergono e vengono entrambi salvati), non solo a livello di storage (già coperto da `PostgresEventStoreTest`, Task 9). Il test di no-op sulla redelivery della coda è già coperto in `MatchPendingTransactionJobTest` (Task 22) e non viene ripetuto qui.

**Interfaces:**
- Nessuna nuova interfaccia — esercita l'intero sistema costruito nei Task 1–27.

- [ ] **Step 1: Write the end-to-end happy-path test (exact match, fully via API)**

```php
<?php

use App\Modules\Reconciliation\Application\ResolveReviewService;
use App\Modules\Reconciliation\Application\TransactionRepository;
use App\Modules\Reconciliation\Domain\Events\TransactionEventTypes;
use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('reconciles a transaction end-to-end through the API on an exact match', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);

    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    // QUEUE_CONNECTION=sync in .env.testing: il job di matching è già eseguito.
    $this->getJson("/api/transactions/{$id}")
        ->assertOk()
        ->assertJsonPath('state', 'Reconciled')
        ->assertJsonCount(3, 'history'); // imported, matched, reconciled
});

it('resolves a transaction end-to-end through the API when review is needed', function () {
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    ExpectedPayment::factory()->create(['reference' => 'REF-1', 'amount_minor_units' => 999, 'currency' => 'EUR']);

    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    $this->getJson("/api/transactions/{$id}")->assertJsonPath('state', 'NeedsReview');

    $candidateId = ExpectedPayment::query()->where('amount_minor_units', 12345)->first()->id;
    $this->postJson("/api/transactions/{$id}/resolve", ['action' => 'confirm', 'expected_payment_id' => $candidateId])
        ->assertOk()
        ->assertJsonPath('state', 'Reconciled');

    // Stream: 0=transaction.imported, 1=transaction.marked_ambiguous, 2=transaction.reconciled (manual).
    $this->getJson("/api/transactions/{$id}")
        ->assertJsonCount(3, 'history')
        ->assertJsonPath('history.2.event_type', 'transaction.reconciled');
});

it('leaves an Unmatched transaction Unmatched end-to-end', function () {
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-NO-CANDIDATE,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    $this->getJson("/api/transactions/{$id}")->assertJsonPath('state', 'Unmatched');
});
```

- [ ] **Step 2: Write the application-level concurrency test**

```php
it('rejects the loser of two concurrent resolutions with a ConcurrencyConflictException', function () {
    $repository = new TransactionRepository(new PostgresEventStore(TransactionEventTypes::map()));

    ExpectedPayment::factory()->count(2)->create(['reference' => 'REF-1', 'amount_minor_units' => 12345, 'currency' => 'EUR']);
    $file = UploadedFile::fake()->createWithContent('statement.csv', "reference,amount_minor_units,currency,statement_date\nREF-1,12345,EUR,2026-07-31");
    $import = $this->postJson('/api/imports', ['file' => $file])->assertOk();
    $id = $import->json('transaction_ids.0');

    // Il matching per REF-1 con due candidati esatti produce NeedsReview (multiple_candidates, spec §6.2).
    $transactionId = \App\Modules\SharedKernel\Domain\TransactionId::fromString($id);
    $candidateId = ExpectedPayment::query()->where('reference', 'REF-1')->first()->id;

    // Due copie indipendenti dello stesso aggregate, caricate dallo stesso stato iniziale — simula la race:
    // entrambe partono da NeedsReview alla stessa versione, solo la prima save() può vincere.
    $firstCopy = $repository->find($transactionId);
    $secondCopy = $repository->find($transactionId);

    $firstCopy->resolveByConfirming($candidateId, Actor::apiCaller('reviewer-1'), 'c1', 'r1');
    $secondCopy->resolveByRejecting('changed my mind', Actor::apiCaller('reviewer-2'), 'c2', 'r2');

    $repository->save($firstCopy);

    expect(fn () => $repository->save($secondCopy))->toThrow(ConcurrencyConflictException::class);

    // Lo stato persistito riflette il vincitore della race, non il perdente.
    expect($repository->find($transactionId)->state())->toBe(\App\Modules\Reconciliation\Domain\TransactionState::Reconciled);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=EndToEndReconciliationTest`
Expected: FAIL se una qualunque delle assunzioni sui task precedenti fosse sbagliata (es. numero di eventi in `history`, ordine dei candidati). Se tutti i task 1–27 sono stati implementati correttamente, questi test dovrebbero già passare al primo run: non introducono codice di produzione nuovo, verificano solo l'integrazione di quanto già costruito.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=EndToEndReconciliationTest`
Expected: PASS. Se `assertJsonCount(3, 'history')` fallisce per un conteggio diverso, verifica l'ordine effettivo dei tre eventi (`imported`, `matched`, `reconciled`) invece di modificare l'asserzione alla cieca — un conteggio o ordine inatteso è quasi certamente un bug nel Task 13 (`markMatched` deve registrare esattamente `TransactionMatched` poi `TransactionReconciled`).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Modules/Reconciliation/EndToEndReconciliationTest.php
git commit -m "test(reconciliation): add end-to-end API and application-level concurrency tests (spec §10)"
```

---

## Task 29: Verifica finale — suite completa e checklist di copertura spec

**Files:** nessuno (task di verifica, non produce codice)

- [ ] **Step 1: Run the entire test suite**

```bash
php artisan test
```

Expected: PASS su tutti i test dei Task 1–28, nessun test skippato, nessun warning di deprecazione da PHP 8.3 o Laravel 13.

- [ ] **Step 2: Verifica manuale via curl (facoltativa ma consigliata) su un server locale**

```bash
php artisan serve &
curl -s -X POST http://127.0.0.1:8000/api/imports -F "file=@/dev/stdin;filename=statement.csv;type=text/csv" <<'CSV'
reference,amount_minor_units,currency,statement_date
REF-DEMO,10000,EUR,2026-08-01
CSV
```

Expected: risposta JSON `200` con `rows_imported: 1`. Ferma il server (`kill %1`) al termine.

- [ ] **Step 3: Checklist di copertura dello spec §2–§11**

Verifica che ogni riga sia vera prima di considerare il piano completo:

- [ ] Shared Kernel completo: `AggregateRoot`, `DomainEvent`, `Money`, `Currency`, `TransactionId`, `IdempotencyKey`, `EventStore`/`PostgresEventStore` (Task 2–9).
- [ ] CSV import, matching engine, manual review implementati nel modulo `Reconciliation` (Task 10–23).
- [ ] REST API come unica interfaccia, nessun pannello admin (Task 24–27; nessun pacchetto Filament in `composer.json`).
- [ ] Stati `Settled`/`Archived` assenti da `TransactionState` (Task 10) — verificato per costruzione, non serve un controllo a parte.
- [ ] Expected Payments come seed/fixture (`ExpectedPaymentFactory`, Task 15), nessun endpoint CRUD per crearli.
- [ ] Solo formato CSV custom supportato (Task 19; ADR-005) — nessun parser PagoPA/MT940.
- [ ] Nessuna autenticazione (Task 25–27 non referenziano middleware `auth`; ADR-008).
- [ ] Nessuna logica di fraud detection in nessun file.
- [ ] Idempotenza: `IdempotencyKeyTest`, `TransactionIdTest` (Task 4–5), test di re-import (Task 21, 25, 28).
- [ ] Concorrenza ottimistica: `PostgresEventStoreTest` (Task 9), test di concorrenza applicativa (Task 28).
- [ ] Deadlock evitato per costruzione: nessun `lockForUpdate()`/`SELECT ... FOR UPDATE` in nessun file — verificalo con `grep -r "lockForUpdate\|FOR UPDATE" app/`.
- [ ] Queue retry no-op: `MatchPendingTransactionJobTest` (Task 22).
- [ ] Audit trail completo via `GET /transactions/{id}` (Task 26).
- [ ] I sei stati e le sei transizioni di `TransactionState` sono tutti raggiunti da almeno un test (Task 12–14, 28).

- [ ] **Step 4: Final commit (se la Step 3 ha richiesto correzioni)**

Se la checklist ha rivelato una lacuna, torna al task pertinente, correggi con un nuovo ciclo TDD (nuovo test che fallisce → fix → verde), e crea un commit dedicato — non ammendare i commit dei task precedenti.

Se nessuna correzione è stata necessaria, questo task non produce un commit proprio: la sua verifica è già interamente coperta dai commit dei Task 1–28.
