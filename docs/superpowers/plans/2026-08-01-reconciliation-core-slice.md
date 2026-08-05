# Reconciliation Core Slice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the v1 vertical slice of the Financial Reconciliation Engine: import a CSV bank statement, match transactions against seeded expected payments, and reconcile them, with full idempotency, optimistic-concurrency safety, and an immutable event-sourced audit trail — exposed only via a REST API.

**Architecture:** Modular monolith (`SharedKernel`, `Ingestion`, `Matching` modules) under `app/Modules`. The `Transaction` aggregate is event-sourced (hand-rolled event store, no package) and owned by the `Matching` module's Domain layer; `Ingestion` depends on `Matching`'s public Domain API (`Transaction`, `TransactionRepository`) to create transactions — this is a one-directional dependency from a supporting module (Ingestion) onto the core domain (Matching), not a cycle. Every state transition is a domain event; a synchronous projector maintains a queryable read model (`transaction_projections`) from those events.

**Tech Stack:** PHP 8.3+, Laravel 13, PostgreSQL, Redis (queues), Pest.

## Global Constraints

- PHP 8.3+, Laravel 13, PostgreSQL, Redis queues, Pest — per spec §3.
- No admin panel / no Filament — REST API is the only interface (spec §7, §9).
- No authentication/authorization in v1 — assume a trusted caller (spec §2, §7).
- Event sourcing is hand-rolled (no `spatie/laravel-event-sourcing` or similar package) — spec §4.
- Money is always integer minor units + a `Currency` enum — never floats (spec §4).
- Expected Payments are seed/fixture data (factories), not a managed module (spec §2).
- Out of scope: Settlement, Notification, `Settled`/`Archived` states, real statement formats (PagoPA/MT940), event store snapshots (spec §11).
- Reference spec: `docs/superpowers/specs/2026-08-01-reconciliation-core-slice-design.md`.

---

## File Structure

```
app/
  Modules/
    SharedKernel/
      Domain/
        Actor.php
        DomainEvent.php
        AbstractDomainEvent.php
        AggregateRoot.php
        ValueObjects/
          Currency.php
          Money.php
          TransactionId.php
          IdempotencyKey.php
        Exceptions/
          ConcurrencyConflictException.php
      Infrastructure/
        EventStore/
          StoredEventRecord.php
          EventStore.php
          StoredEventModel.php
          PostgresEventStore.php
    Matching/
      Domain/
        TransactionState.php
        Transaction.php
        MatchingEngine.php
        MatchOutcome.php
        ExpectedPaymentCandidate.php
        Events/
          TransactionImported.php
          TransactionMatched.php
          TransactionMarkedUnmatched.php
          TransactionMarkedAmbiguous.php
          TransactionReconciled.php
          TransactionRejected.php
        Exceptions/
          IllegalTransactionStateTransition.php
          TransactionNotFound.php
      Application/
        TransactionRepository.php
        RunMatchingForTransaction.php
        ResolveNeedsReview.php
      Infrastructure/
        Persistence/
          ExpectedPayment.php
          ExpectedPaymentFinder.php
          TransactionProjection.php
        Projectors/
          TransactionProjector.php
        Jobs/
          RunMatchingJob.php
        Http/
          Controllers/
            TransactionsController.php
            ResolveTransactionController.php
          Requests/
            ResolveTransactionRequest.php
    Ingestion/
      Application/
        StatementLine.php
        CsvStatementParser.php
        ImportStatement.php
        ImportSummary.php
      Infrastructure/
        Http/
          Controllers/
            ImportStatementController.php
          Requests/
            ImportStatementRequest.php
  Providers/
    SharedKernelServiceProvider.php

database/
  migrations/
    2026_08_01_000001_create_stored_events_table.php
    2026_08_01_000002_create_expected_payments_table.php
    2026_08_01_000003_create_transaction_projections_table.php
  factories/
    Modules/Matching/ExpectedPaymentFactory.php

routes/
  api.php

tests/
  Unit/Modules/SharedKernel/MoneyTest.php
  Unit/Modules/SharedKernel/ValueObjectsTest.php
  Unit/Modules/SharedKernel/AggregateRootTest.php
  Unit/Modules/SharedKernel/PostgresEventStoreTest.php
  Unit/Modules/Matching/TransactionTest.php
  Unit/Modules/Matching/MatchingEngineTest.php
  Feature/Modules/Matching/TransactionRepositoryTest.php
  Feature/Modules/Ingestion/ImportStatementTest.php
  Feature/Modules/Ingestion/ImportStatementEndpointTest.php
  Feature/Modules/Matching/RunMatchingJobTest.php
  Feature/Modules/Matching/ResolveTransactionEndpointTest.php
  Feature/Modules/Matching/TransactionsEndpointTest.php
  Feature/ReconciliationFlowTest.php
```

Each PHP class lives in exactly one file matching its namespace, following `App\Modules\{Module}\{Layer}\...` PSR-4 autoloading already provided by the default `App\` → `app/` mapping.

---

### Task 1: Money and Currency value objects

**Files:**
- Create: `app/Modules/SharedKernel/Domain/ValueObjects/Currency.php`
- Create: `app/Modules/SharedKernel/Domain/ValueObjects/Money.php`
- Test: `tests/Unit/Modules/SharedKernel/MoneyTest.php`

**Interfaces:**
- Produces: `Currency` (enum, cases `EUR`, `USD`, backed by string). `Money::fromMinorUnits(int $amountMinorUnits, Currency $currency): Money`; `Money::amountMinorUnits(): int`; `Money::currency(): Currency`; `Money::equals(Money $other): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\ValueObjects\Currency;
use App\Modules\SharedKernel\Domain\ValueObjects\Money;

it('creates money from minor units', function () {
    $money = Money::fromMinorUnits(12550, Currency::EUR);

    expect($money->amountMinorUnits())->toBe(12550)
        ->and($money->currency())->toBe(Currency::EUR);
});

it('considers two money values with the same amount and currency equal', function () {
    $a = Money::fromMinorUnits(500, Currency::EUR);
    $b = Money::fromMinorUnits(500, Currency::EUR);

    expect($a->equals($b))->toBeTrue();
});

it('considers money with different currencies not equal', function () {
    $a = Money::fromMinorUnits(500, Currency::EUR);
    $b = Money::fromMinorUnits(500, Currency::USD);

    expect($a->equals($b))->toBeFalse();
});

it('rejects a negative amount', function () {
    Money::fromMinorUnits(-1, Currency::EUR);
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Modules/SharedKernel/MoneyTest.php`
Expected: FAIL with "Class ... Currency not found" (classes don't exist yet).

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain\ValueObjects;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain\ValueObjects;

final class Money
{
    private function __construct(
        private readonly int $amountMinorUnits,
        private readonly Currency $currency,
    ) {
        if ($amountMinorUnits < 0) {
            throw new \InvalidArgumentException('Money amount cannot be negative');
        }
    }

    public static function fromMinorUnits(int $amountMinorUnits, Currency $currency): self
    {
        return new self($amountMinorUnits, $currency);
    }

    public function amountMinorUnits(): int
    {
        return $this->amountMinorUnits;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function equals(Money $other): bool
    {
        return $this->amountMinorUnits === $other->amountMinorUnits
            && $this->currency === $other->currency;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Modules/SharedKernel/MoneyTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/ValueObjects/Currency.php app/Modules/SharedKernel/Domain/ValueObjects/Money.php tests/Unit/Modules/SharedKernel/MoneyTest.php
git commit -m "feat(shared-kernel): add Money and Currency value objects"
```

---

### Task 2: TransactionId, IdempotencyKey, Actor

**Files:**
- Create: `app/Modules/SharedKernel/Domain/ValueObjects/TransactionId.php`
- Create: `app/Modules/SharedKernel/Domain/ValueObjects/IdempotencyKey.php`
- Create: `app/Modules/SharedKernel/Domain/Actor.php`
- Test: `tests/Unit/Modules/SharedKernel/ValueObjectsTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `TransactionId::generate(): TransactionId`, `TransactionId::fromString(string): TransactionId`, `->toString(): string`. `IdempotencyKey::fromContent(string ...$parts): IdempotencyKey`, `->toString(): string`. `Actor::system(): Actor`, `Actor::api(string $identifier): Actor`, `->toArray(): array{type: string, identifier: ?string}`, `Actor::fromArray(array): Actor`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ValueObjects\IdempotencyKey;
use App\Modules\SharedKernel\Domain\ValueObjects\TransactionId;

it('generates a unique transaction id each time', function () {
    expect(TransactionId::generate()->toString())
        ->not->toBe(TransactionId::generate()->toString());
});

it('rebuilds a transaction id from a string', function () {
    expect(TransactionId::fromString('abc-123')->toString())->toBe('abc-123');
});

it('derives the same idempotency key from the same content', function () {
    $a = IdempotencyKey::fromContent('file-1', 'REF-1', '1000', 'EUR');
    $b = IdempotencyKey::fromContent('file-1', 'REF-1', '1000', 'EUR');

    expect($a->toString())->toBe($b->toString());
});

it('derives a different idempotency key from different content', function () {
    $a = IdempotencyKey::fromContent('file-1', 'REF-1', '1000', 'EUR');
    $b = IdempotencyKey::fromContent('file-1', 'REF-2', '1000', 'EUR');

    expect($a->toString())->not->toBe($b->toString());
});

it('builds a system actor', function () {
    expect(Actor::system()->toArray())->toBe(['type' => 'system', 'identifier' => null]);
});

it('round-trips an api actor through an array', function () {
    $actor = Actor::api('caller-42');

    expect(Actor::fromArray($actor->toArray())->toArray())->toBe($actor->toArray());
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Modules/SharedKernel/ValueObjectsTest.php`
Expected: FAIL (classes don't exist yet)

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain\ValueObjects;

use Illuminate\Support\Str;

final class TransactionId
{
    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self((string) Str::uuid());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(TransactionId $other): bool
    {
        return $this->value === $other->value;
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain\ValueObjects;

final class IdempotencyKey
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromContent(string ...$parts): self
    {
        return new self(hash('sha256', implode('|', $parts)));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(IdempotencyKey $other): bool
    {
        return $this->value === $other->value;
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $identifier,
    ) {
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    public static function api(string $identifier): self
    {
        return new self('api', $identifier);
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'identifier' => $this->identifier];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['type'], $data['identifier'] ?? null);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Modules/SharedKernel/ValueObjectsTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/ValueObjects/TransactionId.php app/Modules/SharedKernel/Domain/ValueObjects/IdempotencyKey.php app/Modules/SharedKernel/Domain/Actor.php tests/Unit/Modules/SharedKernel/ValueObjectsTest.php
git commit -m "feat(shared-kernel): add TransactionId, IdempotencyKey, Actor"
```

---

### Task 3: DomainEvent, AbstractDomainEvent, AggregateRoot

**Files:**
- Create: `app/Modules/SharedKernel/Domain/DomainEvent.php`
- Create: `app/Modules/SharedKernel/Domain/AbstractDomainEvent.php`
- Create: `app/Modules/SharedKernel/Domain/AggregateRoot.php`
- Test: `tests/Unit/Modules/SharedKernel/AggregateRootTest.php`

**Interfaces:**
- Consumes: `Actor` (Task 2).
- Produces: `DomainEvent` interface (`eventType(): string`, `toPayload(): array`, `actor(): Actor`, `occurredAt(): DateTimeImmutable`, `correlationId(): string`, `causationId(): ?string`). `AbstractDomainEvent` implements the metadata accessors from constructor args, subclasses implement `eventType()`/`toPayload()`. `AggregateRoot` (abstract): `releaseEvents(): DomainEvent[]`, `version(): int`, and protected `recordThat(DomainEvent $event): void`, `replay(array $events): void`, abstract `apply(DomainEvent $event): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;

final class FakeThingCreated extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $thingId,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'fake_thing.created';
    }

    public function toPayload(): array
    {
        return ['thing_id' => $this->thingId];
    }
}

final class FakeThing extends AggregateRoot
{
    private string $id;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function create(string $id): self
    {
        $thing = new self($id);
        $thing->recordThat(new FakeThingCreated(Actor::system(), new DateTimeImmutable(), 'corr-1', null, $id));

        return $thing;
    }

    public static function reconstitute(string $id, array $events): self
    {
        $thing = new self($id);
        $thing->replay($events);

        return $thing;
    }

    protected function apply(DomainEvent $event): void
    {
        // nothing further to fold for this fake aggregate
    }

    public function id(): string
    {
        return $this->id;
    }
}

it('records events and increments the version', function () {
    $thing = FakeThing::create('thing-1');

    expect($thing->version())->toBe(1)
        ->and($thing->releaseEvents())->toHaveCount(1)
        ->and($thing->releaseEvents())->toHaveCount(0); // released events are cleared
});

it('replays history to reach the same version without re-recording events', function () {
    $original = FakeThing::create('thing-1');
    $history = $original->releaseEvents();

    $rebuilt = FakeThing::reconstitute('thing-1', $history);

    expect($rebuilt->version())->toBe(1)
        ->and($rebuilt->releaseEvents())->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Modules/SharedKernel/AggregateRootTest.php`
Expected: FAIL (classes don't exist yet)

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain;

interface DomainEvent
{
    public function eventType(): string;

    public function toPayload(): array;

    public function actor(): Actor;

    public function occurredAt(): \DateTimeImmutable;

    public function correlationId(): string;

    public function causationId(): ?string;
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

abstract class AbstractDomainEvent implements DomainEvent
{
    public function __construct(
        private readonly Actor $actor,
        private readonly \DateTimeImmutable $occurredAt,
        private readonly string $correlationId,
        private readonly ?string $causationId = null,
    ) {
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Domain;

abstract class AggregateRoot
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private int $version = 0;

    abstract protected function apply(DomainEvent $event): void;

    protected function recordThat(DomainEvent $event): void
    {
        $this->apply($event);
        $this->recordedEvents[] = $event;
        $this->version++;
    }

    /** @param DomainEvent[] $events */
    protected function replay(array $events): void
    {
        foreach ($events as $event) {
            $this->apply($event);
            $this->version++;
        }
    }

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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Modules/SharedKernel/AggregateRootTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/SharedKernel/Domain/DomainEvent.php app/Modules/SharedKernel/Domain/AbstractDomainEvent.php app/Modules/SharedKernel/Domain/AggregateRoot.php tests/Unit/Modules/SharedKernel/AggregateRootTest.php
git commit -m "feat(shared-kernel): add DomainEvent, AbstractDomainEvent, AggregateRoot"
```

---

### Task 4: Event store infrastructure

**Files:**
- Create: `app/Modules/SharedKernel/Domain/Exceptions/ConcurrencyConflictException.php`
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/StoredEventRecord.php`
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/EventStore.php`
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/StoredEventModel.php`
- Create: `app/Modules/SharedKernel/Infrastructure/EventStore/PostgresEventStore.php`
- Create: `app/Providers/SharedKernelServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Create: `database/migrations/2026_08_01_000001_create_stored_events_table.php`
- Test: `tests/Unit/Modules/SharedKernel/PostgresEventStoreTest.php`

**Interfaces:**
- Consumes: `DomainEvent`, `Actor` (Task 3, Task 2).
- Produces: `EventStore` interface — `append(string $aggregateId, int $expectedVersion, DomainEvent[] $events): void` (throws `ConcurrencyConflictException` on a version clash), `load(string $aggregateId): StoredEventRecord[]`. `StoredEventRecord` (readonly DTO: `aggregateId`, `version`, `eventType`, `payload`, `actor`, `correlationId`, `causationId`, `occurredAt`). Bound in the container: `EventStore::class` → `PostgresEventStore::class`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stored_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_id');
            $table->unsignedInteger('version');
            $table->string('event_type');
            $table->jsonb('payload');
            $table->jsonb('actor');
            $table->uuid('correlation_id');
            $table->uuid('causation_id')->nullable();
            $table->timestampTz('occurred_at');

            $table->unique(['aggregate_id', 'version']);
            $table->index('aggregate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_events');
    }
};
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use App\Modules\SharedKernel\Infrastructure\EventStore\EventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\DomainEvent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

final class FakeRecorded extends AbstractDomainEvent
{
    public function __construct(Actor $actor, DateTimeImmutable $occurredAt, string $correlationId, public readonly string $note)
    {
        parent::__construct($actor, $occurredAt, $correlationId, null);
    }

    public function eventType(): string
    {
        return 'fake.recorded';
    }

    public function toPayload(): array
    {
        return ['note' => $this->note];
    }
}

function fakeEvent(string $note): DomainEvent
{
    return new FakeRecorded(Actor::system(), new DateTimeImmutable(), 'corr-1', $note);
}

it('appends events and loads them back in order', function () {
    $store = app(EventStore::class);

    $store->append('agg-1', 0, [fakeEvent('first'), fakeEvent('second')]);

    $records = $store->load('agg-1');

    expect($records)->toHaveCount(2)
        ->and($records[0]->version)->toBe(1)
        ->and($records[0]->payload)->toBe(['note' => 'first'])
        ->and($records[1]->version)->toBe(2);
});

it('rejects appending against a stale expected version', function () {
    $store = app(EventStore::class);

    $store->append('agg-2', 0, [fakeEvent('first')]);

    expect(fn () => $store->append('agg-2', 0, [fakeEvent('conflict')]))
        ->toThrow(ConcurrencyConflictException::class);
});

it('returns an empty array for an unknown aggregate', function () {
    $store = app(EventStore::class);

    expect($store->load('missing'))->toBe([]);
});
```

- [ ] **Step 3: Run migration and test to verify it fails**

Run: `php artisan migrate && php artisan test tests/Unit/Modules/SharedKernel/PostgresEventStoreTest.php`
Expected: FAIL (classes don't exist yet)

- [ ] **Step 4: Write the implementation**

```php
<?php

namespace App\Modules\SharedKernel\Domain\Exceptions;

final class ConcurrencyConflictException extends \RuntimeException
{
    public function __construct(string $aggregateId, int $expectedVersion)
    {
        parent::__construct("Concurrency conflict appending to aggregate {$aggregateId} at expected version {$expectedVersion}");
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

final class StoredEventRecord
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $version,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly array $actor,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;

interface EventStore
{
    /**
     * @param  DomainEvent[]  $events
     *
     * @throws ConcurrencyConflictException
     */
    public function append(string $aggregateId, int $expectedVersion, array $events): void;

    /** @return StoredEventRecord[] */
    public function load(string $aggregateId): array;
}
```

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use Illuminate\Database\Eloquent\Model;

final class StoredEventModel extends Model
{
    public $timestamps = false;

    protected $table = 'stored_events';

    protected $fillable = [
        'aggregate_id', 'version', 'event_type', 'payload', 'actor',
        'correlation_id', 'causation_id', 'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'actor' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
```

```php
<?php

namespace App\Modules\SharedKernel\Infrastructure\EventStore;

use App\Modules\SharedKernel\Domain\Exceptions\ConcurrencyConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PostgresEventStore implements EventStore
{
    public function append(string $aggregateId, int $expectedVersion, array $events): void
    {
        DB::transaction(function () use ($aggregateId, $expectedVersion, $events) {
            $version = $expectedVersion;

            foreach ($events as $event) {
                $version++;

                try {
                    StoredEventModel::create([
                        'aggregate_id' => $aggregateId,
                        'version' => $version,
                        'event_type' => $event->eventType(),
                        'payload' => $event->toPayload(),
                        'actor' => $event->actor()->toArray(),
                        'correlation_id' => $event->correlationId(),
                        'causation_id' => $event->causationId(),
                        'occurred_at' => $event->occurredAt(),
                    ]);
                } catch (QueryException $e) {
                    if ((string) $e->getCode() === '23505') {
                        throw new ConcurrencyConflictException($aggregateId, $expectedVersion);
                    }

                    throw $e;
                }
            }
        });
    }

    public function load(string $aggregateId): array
    {
        return StoredEventModel::where('aggregate_id', $aggregateId)
            ->orderBy('version')
            ->get()
            ->map(fn (StoredEventModel $row) => new StoredEventRecord(
                $row->aggregate_id,
                $row->version,
                $row->event_type,
                $row->payload,
                $row->actor,
                $row->correlation_id,
                $row->causation_id,
                $row->occurred_at,
            ))
            ->all();
    }
}
```

```php
<?php

namespace App\Providers;

use App\Modules\SharedKernel\Infrastructure\EventStore\EventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\PostgresEventStore;
use Illuminate\Support\ServiceProvider;

final class SharedKernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventStore::class, PostgresEventStore::class);
    }
}
```

Register the provider by adding it to the array in `bootstrap/providers.php`:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SharedKernelServiceProvider::class,
];
```

- [ ] **Step 5: Run migration and test to verify it passes**

Run: `php artisan migrate && php artisan test tests/Unit/Modules/SharedKernel/PostgresEventStoreTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/SharedKernel/Domain/Exceptions/ConcurrencyConflictException.php app/Modules/SharedKernel/Infrastructure/EventStore/ app/Providers/SharedKernelServiceProvider.php bootstrap/providers.php database/migrations/2026_08_01_000001_create_stored_events_table.php tests/Unit/Modules/SharedKernel/PostgresEventStoreTest.php
git commit -m "feat(shared-kernel): add Postgres-backed event store with optimistic concurrency"
```

---

### Task 5: Transaction domain events, state enum, exceptions

**Files:**
- Create: `app/Modules/Matching/Domain/TransactionState.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionImported.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionMatched.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionMarkedUnmatched.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionMarkedAmbiguous.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionReconciled.php`
- Create: `app/Modules/Matching/Domain/Events/TransactionRejected.php`
- Create: `app/Modules/Matching/Domain/Exceptions/IllegalTransactionStateTransition.php`
- Create: `app/Modules/Matching/Domain/Exceptions/TransactionNotFound.php`
- Test: covered by Task 6's `TransactionTest.php` (these are pure data classes with no independent branching logic worth testing in isolation)

**Interfaces:**
- Consumes: `AbstractDomainEvent`, `Actor` (Task 3, Task 2).
- Produces: `TransactionState` enum (string-backed: `Pending`, `Matched`, `Unmatched`, `NeedsReview`, `Reconciled`, `Rejected` — note `Matched` is a transient event, never a resting projected state, see Task 6). Six event classes, each with `eventType()` returning a `transaction.*` string and public readonly business fields as listed below. `IllegalTransactionStateTransition(string $transactionId, TransactionState $actual, TransactionState $required)`. `TransactionNotFound(string $transactionId)`.

- [ ] **Step 1: Write the implementation**

```php
<?php

namespace App\Modules\Matching\Domain;

enum TransactionState: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case NeedsReview = 'needs_review';
    case Reconciled = 'reconciled';
    case Rejected = 'rejected';
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionImported extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
        public readonly int $amountMinorUnits,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $idempotencyKey,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.imported';
    }

    public function toPayload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'amount_minor_units' => $this->amountMinorUnits,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionMatched extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
        public readonly string $expectedPaymentId,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.matched';
    }

    public function toPayload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'expected_payment_id' => $this->expectedPaymentId,
        ];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionMarkedUnmatched extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.marked_unmatched';
    }

    public function toPayload(): array
    {
        return ['transaction_id' => $this->transactionId];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionMarkedAmbiguous extends AbstractDomainEvent
{
    /** @param string[] $candidateExpectedPaymentIds */
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
        public readonly array $candidateExpectedPaymentIds,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.marked_ambiguous';
    }

    public function toPayload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'candidate_expected_payment_ids' => $this->candidateExpectedPaymentIds,
        ];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionReconciled extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
        public readonly string $expectedPaymentId,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.reconciled';
    }

    public function toPayload(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'expected_payment_id' => $this->expectedPaymentId,
        ];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Events;

use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\Actor;

final class TransactionRejected extends AbstractDomainEvent
{
    public function __construct(
        Actor $actor,
        \DateTimeImmutable $occurredAt,
        string $correlationId,
        ?string $causationId,
        public readonly string $transactionId,
    ) {
        parent::__construct($actor, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return 'transaction.rejected';
    }

    public function toPayload(): array
    {
        return ['transaction_id' => $this->transactionId];
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Exceptions;

use App\Modules\Matching\Domain\TransactionState;

final class IllegalTransactionStateTransition extends \RuntimeException
{
    public function __construct(string $transactionId, TransactionState $actual, TransactionState $required)
    {
        parent::__construct(sprintf(
            'Transaction %s is in state %s, expected %s',
            $transactionId,
            $actual->value,
            $required->value,
        ));
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain\Exceptions;

final class TransactionNotFound extends \RuntimeException
{
    public function __construct(string $transactionId)
    {
        parent::__construct("Transaction {$transactionId} not found");
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Modules/Matching/Domain/TransactionState.php app/Modules/Matching/Domain/Events/ app/Modules/Matching/Domain/Exceptions/
git commit -m "feat(matching): add Transaction domain events, state enum, exceptions"
```

---

### Task 6: Transaction aggregate root

**Files:**
- Create: `app/Modules/Matching/Domain/Transaction.php`
- Test: `tests/Unit/Modules/Matching/TransactionTest.php`

**Interfaces:**
- Consumes: `AggregateRoot`, `Actor`, `DomainEvent` (Task 3), `Money`, `Currency` (Task 1), all six Transaction events, `TransactionState`, `IllegalTransactionStateTransition` (Task 5).
- Produces: `Transaction::import(string $id, Money $amount, string $reference, string $idempotencyKey, Actor $actor, string $correlationId): Transaction`; `Transaction::reconstitute(string $id, DomainEvent[] $events): Transaction`; `->match(string $expectedPaymentId, Actor $actor, string $correlationId, ?string $causationId): void`; `->markUnmatched(...)`; `->markAmbiguous(array $candidateExpectedPaymentIds, ...)`; `->resolveByReconciling(string $expectedPaymentId, ...)`; `->reject(...)`; `->id(): string`; `->state(): TransactionState`; `->amount(): Money`; `->reference(): string`. All command methods throw `IllegalTransactionStateTransition` when called from the wrong state.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Matching\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\Matching\Domain\Transaction;
use App\Modules\Matching\Domain\TransactionState;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ValueObjects\Currency;
use App\Modules\SharedKernel\Domain\ValueObjects\Money;

function importedTransaction(): Transaction
{
    return Transaction::import(
        'tx-1',
        Money::fromMinorUnits(1000, Currency::EUR),
        'INV-1',
        'idem-key-1',
        Actor::system(),
        'corr-1',
    );
}

it('starts in the pending state after import', function () {
    $tx = importedTransaction();

    expect($tx->state())->toBe(TransactionState::Pending)
        ->and($tx->amount()->amountMinorUnits())->toBe(1000)
        ->and($tx->reference())->toBe('INV-1')
        ->and($tx->version())->toBe(1);
});

it('reconciles automatically when matched', function () {
    $tx = importedTransaction();
    $tx->releaseEvents();

    $tx->match('exp-1', Actor::system(), 'corr-2', null);

    expect($tx->state())->toBe(TransactionState::Reconciled)
        ->and($tx->releaseEvents())->toHaveCount(2); // matched + reconciled
});

it('marks unmatched when no candidate is found', function () {
    $tx = importedTransaction();

    $tx->markUnmatched(Actor::system(), 'corr-2', null);

    expect($tx->state())->toBe(TransactionState::Unmatched);
});

it('marks ambiguous when multiple candidates are found', function () {
    $tx = importedTransaction();

    $tx->markAmbiguous(['exp-1', 'exp-2'], Actor::system(), 'corr-2', null);

    expect($tx->state())->toBe(TransactionState::NeedsReview);
});

it('resolves a needs-review transaction by reconciling it', function () {
    $tx = importedTransaction();
    $tx->markAmbiguous(['exp-1', 'exp-2'], Actor::system(), 'corr-2', null);

    $tx->resolveByReconciling('exp-1', Actor::api('reviewer-1'), 'corr-3', null);

    expect($tx->state())->toBe(TransactionState::Reconciled);
});

it('resolves a needs-review transaction by rejecting it', function () {
    $tx = importedTransaction();
    $tx->markAmbiguous(['exp-1', 'exp-2'], Actor::system(), 'corr-2', null);

    $tx->reject(Actor::api('reviewer-1'), 'corr-3', null);

    expect($tx->state())->toBe(TransactionState::Rejected);
});

it('refuses to match a transaction that is not pending', function () {
    $tx = importedTransaction();
    $tx->markUnmatched(Actor::system(), 'corr-2', null);

    expect(fn () => $tx->match('exp-1', Actor::system(), 'corr-3', null))
        ->toThrow(IllegalTransactionStateTransition::class);
});

it('refuses to resolve a transaction that is not in needs-review', function () {
    $tx = importedTransaction();

    expect(fn () => $tx->resolveByReconciling('exp-1', Actor::api('r'), 'corr-2', null))
        ->toThrow(IllegalTransactionStateTransition::class);
});

it('reconstitutes an identical state from replayed history without re-recording events', function () {
    $tx = importedTransaction();
    $tx->markAmbiguous(['exp-1'], Actor::system(), 'corr-2', null);
    $history = $tx->releaseEvents();

    $rebuilt = Transaction::reconstitute('tx-1', array_merge(
        [importedTransaction()->releaseEvents()[0]],
        [$history[0]],
    ));

    expect($rebuilt->state())->toBe(TransactionState::NeedsReview)
        ->and($rebuilt->releaseEvents())->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Modules/Matching/TransactionTest.php`
Expected: FAIL (`Transaction` class doesn't exist yet)

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Modules\Matching\Domain;

use App\Modules\Matching\Domain\Events\TransactionImported;
use App\Modules\Matching\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Matching\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Matching\Domain\Events\TransactionMatched;
use App\Modules\Matching\Domain\Events\TransactionReconciled;
use App\Modules\Matching\Domain\Events\TransactionRejected;
use App\Modules\Matching\Domain\Exceptions\IllegalTransactionStateTransition;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Domain\ValueObjects\Currency;
use App\Modules\SharedKernel\Domain\ValueObjects\Money;

final class Transaction extends AggregateRoot
{
    private string $id;

    private TransactionState $state;

    private Money $amount;

    private string $reference;

    private function __construct(string $id)
    {
        $this->id = $id;
    }

    public static function import(
        string $id,
        Money $amount,
        string $reference,
        string $idempotencyKey,
        Actor $actor,
        string $correlationId,
    ): self {
        $transaction = new self($id);
        $transaction->recordThat(new TransactionImported(
            $actor,
            new \DateTimeImmutable(),
            $correlationId,
            null,
            $id,
            $amount->amountMinorUnits(),
            $amount->currency()->value,
            $reference,
            $idempotencyKey,
        ));

        return $transaction;
    }

    /** @param DomainEvent[] $events */
    public static function reconstitute(string $id, array $events): self
    {
        $transaction = new self($id);
        $transaction->replay($events);

        return $transaction;
    }

    public function match(string $expectedPaymentId, Actor $actor, string $correlationId, ?string $causationId): void
    {
        $this->assertState(TransactionState::Pending);
        $this->recordThat(new TransactionMatched($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id, $expectedPaymentId));
        $this->recordThat(new TransactionReconciled($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id, $expectedPaymentId));
    }

    public function markUnmatched(Actor $actor, string $correlationId, ?string $causationId): void
    {
        $this->assertState(TransactionState::Pending);
        $this->recordThat(new TransactionMarkedUnmatched($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id));
    }

    /** @param string[] $candidateExpectedPaymentIds */
    public function markAmbiguous(array $candidateExpectedPaymentIds, Actor $actor, string $correlationId, ?string $causationId): void
    {
        $this->assertState(TransactionState::Pending);
        $this->recordThat(new TransactionMarkedAmbiguous($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id, $candidateExpectedPaymentIds));
    }

    public function resolveByReconciling(string $expectedPaymentId, Actor $actor, string $correlationId, ?string $causationId): void
    {
        $this->assertState(TransactionState::NeedsReview);
        $this->recordThat(new TransactionReconciled($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id, $expectedPaymentId));
    }

    public function reject(Actor $actor, string $correlationId, ?string $causationId): void
    {
        $this->assertState(TransactionState::NeedsReview);
        $this->recordThat(new TransactionRejected($actor, new \DateTimeImmutable(), $correlationId, $causationId, $this->id));
    }

    private function assertState(TransactionState $required): void
    {
        if ($this->state !== $required) {
            throw new IllegalTransactionStateTransition($this->id, $this->state, $required);
        }
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof TransactionImported => $this->onImported($event),
            $event instanceof TransactionMatched => $this->state = TransactionState::Matched,
            $event instanceof TransactionMarkedUnmatched => $this->state = TransactionState::Unmatched,
            $event instanceof TransactionMarkedAmbiguous => $this->state = TransactionState::NeedsReview,
            $event instanceof TransactionReconciled => $this->state = TransactionState::Reconciled,
            $event instanceof TransactionRejected => $this->state = TransactionState::Rejected,
            default => throw new \LogicException('Unknown event: ' . $event::class),
        };
    }

    private function onImported(TransactionImported $event): void
    {
        $this->state = TransactionState::Pending;
        $this->amount = Money::fromMinorUnits($event->amountMinorUnits, Currency::from($event->currency));
        $this->reference = $event->reference;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function state(): TransactionState
    {
        return $this->state;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function reference(): string
    {
        return $this->reference;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Modules/Matching/TransactionTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Matching/Domain/Transaction.php tests/Unit/Modules/Matching/TransactionTest.php
git commit -m "feat(matching): add Transaction aggregate root with explicit state machine"
```

---

### Task 7: Transaction read model and projector

**Files:**
- Create: `database/migrations/2026_08_01_000003_create_transaction_projections_table.php`
- Create: `app/Modules/Matching/Infrastructure/Persistence/TransactionProjection.php`
- Create: `app/Modules/Matching/Infrastructure/Projectors/TransactionProjector.php`
- Test: `tests/Feature/Modules/Matching/TransactionRepositoryTest.php` (written in Task 8, exercises the projector indirectly through the repository — see note there)

**Interfaces:**
- Consumes: all six Transaction events, `TransactionState` (Task 5).
- Produces: `TransactionProjection` (Eloquent model, primary key `transaction_id`, table `transaction_projections`). `TransactionProjector::project(DomainEvent $event, int $newVersion): void` — creates the row on `TransactionImported`, updates `state`/`version`/extra fields on every subsequent event.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_projections', function (Blueprint $table) {
            $table->uuid('transaction_id')->primary();
            $table->string('state');
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('currency', 3);
            $table->string('reference');
            $table->string('idempotency_key')->unique();
            $table->uuid('matched_expected_payment_id')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_projections');
    }
};
```

- [ ] **Step 2: Write the implementation**

```php
<?php

namespace App\Modules\Matching\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class TransactionProjection extends Model
{
    protected $table = 'transaction_projections';

    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'transaction_id', 'state', 'amount_minor_units', 'currency',
        'reference', 'idempotency_key', 'matched_expected_payment_id', 'version',
    ];
}
```

```php
<?php

namespace App\Modules\Matching\Infrastructure\Projectors;

use App\Modules\Matching\Domain\Events\TransactionImported;
use App\Modules\Matching\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Matching\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Matching\Domain\Events\TransactionMatched;
use App\Modules\Matching\Domain\Events\TransactionReconciled;
use App\Modules\Matching\Domain\Events\TransactionRejected;
use App\Modules\Matching\Domain\TransactionState;
use App\Modules\Matching\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Domain\DomainEvent;

final class TransactionProjector
{
    public function project(DomainEvent $event, int $newVersion): void
    {
        match (true) {
            $event instanceof TransactionImported => TransactionProjection::create([
                'transaction_id' => $event->transactionId,
                'state' => TransactionState::Pending->value,
                'amount_minor_units' => $event->amountMinorUnits,
                'currency' => $event->currency,
                'reference' => $event->reference,
                'idempotency_key' => $event->idempotencyKey,
                'version' => $newVersion,
            ]),
            $event instanceof TransactionMatched => $this->update($event->transactionId, TransactionState::Matched, $newVersion, [
                'matched_expected_payment_id' => $event->expectedPaymentId,
            ]),
            $event instanceof TransactionMarkedUnmatched => $this->update($event->transactionId, TransactionState::Unmatched, $newVersion),
            $event instanceof TransactionMarkedAmbiguous => $this->update($event->transactionId, TransactionState::NeedsReview, $newVersion),
            $event instanceof TransactionReconciled => $this->update($event->transactionId, TransactionState::Reconciled, $newVersion, [
                'matched_expected_payment_id' => $event->expectedPaymentId,
            ]),
            $event instanceof TransactionRejected => $this->update($event->transactionId, TransactionState::Rejected, $newVersion),
            default => throw new \LogicException('Unknown event: ' . $event::class),
        };
    }

    private function update(string $transactionId, TransactionState $state, int $newVersion, array $extra = []): void
    {
        TransactionProjection::where('transaction_id', $transactionId)->update([
            ...$extra,
            'state' => $state->value,
            'version' => $newVersion,
        ]);
    }
}
```

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: `transaction_projections` table created.

- [ ] **Step 4: Commit**

(No standalone test here — Task 8 tests the projector through `TransactionRepository`, since a projection with no repository to fill it from events would only be testable by hand-building event objects, duplicating Task 8's coverage.)

```bash
git add database/migrations/2026_08_01_000003_create_transaction_projections_table.php app/Modules/Matching/Infrastructure/Persistence/TransactionProjection.php app/Modules/Matching/Infrastructure/Projectors/TransactionProjector.php
git commit -m "feat(matching): add transaction read model and projector"
```

---

### Task 8: TransactionRepository

**Files:**
- Create: `app/Modules/Matching/Application/TransactionRepository.php`
- Test: `tests/Feature/Modules/Matching/TransactionRepositoryTest.php`

**Interfaces:**
- Consumes: `EventStore`, `StoredEventRecord` (Task 4), `Transaction`, all six events (Task 5, 6), `TransactionProjector` (Task 7), `Actor` (Task 2).
- Produces: `TransactionRepository::save(Transaction $transaction, int $expectedVersion): void`; `->get(string $transactionId): ?Transaction`; `->existsByIdempotencyKey(string $idempotencyKey): bool`; `->history(string $transactionId): array` (list of associative arrays for API/audit display: `version`, `event_type`, `payload`, `actor`, `correlation_id`, `causation_id`, `occurred_at`).

This is a Feature test (uses `RefreshDatabase`) because it exercises the real Postgres-backed `EventStore` and the `transaction_projections` table together — that integration is exactly what the class is responsible for.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Modules\Matching\Application\TransactionRepository;
use App\Modules\Matching\Domain\Transaction;
use App\Modules\Matching\Domain\TransactionState;
use App\Modules\Matching\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\ValueObjects\Currency;
use App\Modules\SharedKernel\Domain\ValueObjects\Money;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('saves a new transaction and projects it to the read model', function () {
    $repository = app(TransactionRepository::class);
    $tx = Transaction::import('tx-1', Money::fromMinorUnits(1000, Currency::EUR), 'INV-1', 'idem-1', Actor::system(), 'corr-1');

    $repository->save($tx, 0);

    $projection = TransactionProjection::find('tx-1');
    expect($projection->state)->toBe(TransactionState::Pending->value)
        ->and($projection->version)->toBe(1);
});

it('loads a transaction back with its full state', function () {
    $repository = app(TransactionRepository::class);
    $tx = Transaction::import('tx-2', Money::fromMinorUnits(500, Currency::EUR), 'INV-2', 'idem-2', Actor::system(), 'corr-1');
    $repository->save($tx, 0);

    $loaded = $repository->get('tx-2');

    expect($loaded->state())->toBe(TransactionState::Pending)
        ->and($loaded->amount()->amountMinorUnits())->toBe(500)
        ->and($loaded->version())->toBe(1);
});

it('returns null when loading an unknown transaction', function () {
    expect(app(TransactionRepository::class)->get('missing'))->toBeNull();
});

it('detects an existing idempotency key', function () {
    $repository = app(TransactionRepository::class);
    $tx = Transaction::import('tx-3', Money::fromMinorUnits(500, Currency::EUR), 'INV-3', 'idem-3', Actor::system(), 'corr-1');
    $repository->save($tx, 0);

    expect($repository->existsByIdempotencyKey('idem-3'))->toBeTrue()
        ->and($repository->existsByIdempotencyKey('unknown'))->toBeFalse();
});

it('projects subsequent transitions after loading and re-saving', function () {
    $repository = app(TransactionRepository::class);
    $tx = Transaction::import('tx-4', Money::fromMinorUnits(500, Currency::EUR), 'INV-4', 'idem-4', Actor::system(), 'corr-1');
    $repository->save($tx, 0);

    $loaded = $repository->get('tx-4');
    $expectedVersion = $loaded->version();
    $loaded->markUnmatched(Actor::system(), 'corr-2', null);
    $repository->save($loaded, $expectedVersion);

    $projection = TransactionProjection::find('tx-4');
    expect($projection->state)->toBe(TransactionState::Unmatched->value)
        ->and($projection->version)->toBe(2);
});

it('returns full event history for audit purposes', function () {
    $repository = app(TransactionRepository::class);
    $tx = Transaction::import('tx-5', Money::fromMinorUnits(500, Currency::EUR), 'INV-5', 'idem-5', Actor::system(), 'corr-1');
    $repository->save($tx, 0);

    $history = $repository->history('tx-5');

    expect($history)->toHaveCount(1)
        ->and($history[0]['event_type'])->toBe('transaction.imported')
        ->and($history[0]['version'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Modules/Matching/TransactionRepositoryTest.php`
Expected: FAIL (`TransactionRepository` doesn't exist yet)

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Modules\Matching\Application;

use App\Modules\Matching\Domain\Events\TransactionImported;
use App\Modules\Matching\Domain\Events\TransactionMarkedAmbiguous;
use App\Modules\Matching\Domain\Events\TransactionMarkedUnmatched;
use App\Modules\Matching\Domain\Events\TransactionMatched;
use App\Modules\Matching\Domain\Events\TransactionReconciled;
use App\Modules\Matching\Domain\Events\TransactionRejected;
use App\Modules\Matching\Domain\Transaction;
use App\Modules\Matching\Infrastructure\Persistence\TransactionProjection;
use App\Modules\Matching\Infrastructure\Projectors\TransactionProjector;
use App\Modules\SharedKernel\Domain\Actor;
use App\Modules\SharedKernel\Domain\DomainEvent;
use App\Modules\SharedKernel\Infrastructure\EventStore\EventStore;
use App\Modules\SharedKernel\Infrastructure\EventStore\StoredEventRecord;

final class TransactionRepository
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly TransactionProjector $projector,
    ) {
    }

    public function save(Transaction $transaction, int $expectedVersion): void
    {
        $events = $transaction->releaseEvents();

        if ($events === []) {
            return;
        }

        $this->eventStore->append($transaction->id(), $expectedVersion, $events);

        $version = $expectedVersion;
        foreach ($events as $event) {
            $version++;
            $this->projector->project($event, $version);
        }
    }

    public function get(string $transactionId): ?Transaction
    {
        $records = $this->eventStore->load($transactionId);

        if ($records === []) {
            return null;
        }

        $events = array_map(fn (StoredEventRecord $record) => $this->deserialize($record), $records);

        return Transaction::reconstitute($transactionId, $events);
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        return TransactionProjection::where('idempotency_key', $idempotencyKey)->exists();
    }

    public function history(string $transactionId): array
    {
        return array_map(fn (StoredEventRecord $record) => [
            'version' => $record->version,
            'event_type' => $record->eventType,
            'payload' => $record->payload,
            'actor' => $record->actor,
            'correlation_id' => $record->correlationId,
            'causation_id' => $record->causationId,
            'occurred_at' => $record->occurredAt->format(DATE_ATOM),
        ], $this->eventStore->load($transactionId));
    }

    private function deserialize(StoredEventRecord $record): DomainEvent
    {
        $actor = Actor::fromArray($record->actor);
        $payload = $record->payload;

        return match ($record->eventType) {
            'transaction.imported' => new TransactionImported(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'], $payload['amount_minor_units'], $payload['currency'], $payload['reference'], $payload['idempotency_key'],
            ),
            'transaction.matched' => new TransactionMatched(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'], $payload['expected_payment_id'],
            ),
            'transaction.marked_unmatched' => new TransactionMarkedUnmatched(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'],
            ),
            'transaction.marked_ambiguous' => new TransactionMarkedAmbiguous(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'], $payload['candidate_expected_payment_ids'],
            ),
            'transaction.reconciled' => new TransactionReconciled(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'], $payload['expected_payment_id'],
            ),
            'transaction.rejected' => new TransactionRejected(
                $actor, $record->occurredAt, $record->correlationId, $record->causationId,
                $payload['transaction_id'],
            ),
            default => throw new \LogicException("Unknown event type: {$record->eventType}"),
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Modules/Matching/TransactionRepositoryTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Matching/Application/TransactionRepository.php tests/Feature/Modules/Matching/TransactionRepositoryTest.php
git commit -m "feat(matching): add TransactionRepository bridging event store and read model"
```

---

### Task 9: Expected Payments (seed data) and the MatchingEngine

**Files:**
- Create: `database/migrations/2026_08_01_000002_create_expected_payments_table.php`
- Create: `app/Modules/Matching/Infrastructure/Persistence/ExpectedPayment.php`
- Create: `database/factories/Modules/Matching/ExpectedPaymentFactory.php`
- Create: `database/seeders/ExpectedPaymentSeeder.php`
- Create: `app/Modules/Matching/Domain/ExpectedPaymentCandidate.php`
- Create: `app/Modules/Matching/Infrastructure/Persistence/ExpectedPaymentFinder.php`
- Create: `app/Modules/Matching/Domain/MatchOutcome.php`
- Create: `app/Modules/Matching/Domain/MatchingEngine.php`
- Test: `tests/Unit/Modules/Matching/MatchingEngineTest.php`

**Interfaces:**
- Consumes: `Money`, `Currency` (Task 1).
- Produces: `ExpectedPaymentCandidate` (readonly DTO: `id`, `amountMinorUnits`, `currency`, `reference`). `ExpectedPaymentFinder::findByReference(string $reference): ExpectedPaymentCandidate[]`. `MatchOutcome` — factories `matched(string $expectedPaymentId)`, `unmatched()`, `ambiguous(string[] $candidateIds)`; readonly `type` (`'matched'|'unmatched'|'ambiguous'`), `expectedPaymentId`, `candidateIds`. `MatchingEngine::match(Money $transactionAmount, ExpectedPaymentCandidate[] $candidates): MatchOutcome`.

- [ ] **Step 1: Write the migration, model, factory, seeder**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expected_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference');
            $table->unsignedBigInteger('amount_minor_units');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_payments');
    }
};
```

```php
<?php

namespace App\Modules\Matching\Infrastructure\Persistence;

use Database\Factories\Modules\Matching\ExpectedPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ExpectedPayment extends Model
{
    use HasFactory;

    protected $table = 'expected_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'reference', 'amount_minor_units', 'currency'];

    protected static function newFactory(): ExpectedPaymentFactory
    {
        return ExpectedPaymentFactory::new();
    }
}
```

```php
<?php

namespace Database\Factories\Modules\Matching;

use App\Modules\Matching\Infrastructure\Persistence\ExpectedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class ExpectedPaymentFactory extends Factory
{
    protected $model = ExpectedPayment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'reference' => 'INV-' . $this->faker->unique()->numerify('#####'),
            'amount_minor_units' => $this->faker->numberBetween(1000, 500000),
            'currency' => 'EUR',
        ];
    }
}
```

```php
<?php

namespace Database\Seeders;

use App\Modules\Matching\Infrastructure\Persistence\ExpectedPayment;
use Illuminate\Database\Seeder;

final class ExpectedPaymentSeeder extends Seeder
{
    public function run(): void
    {
        ExpectedPayment::factory()->createMany([
            ['id' => '11111111-1111-1111-1111-111111111111', 'reference' => 'INV-1001', 'amount_minor_units' => 10000, 'currency' => 'EUR'],
            ['id' => '22222222-2222-2222-2222-222222222222', 'reference' => 'INV-1002', 'amount_minor_units' => 25000, 'currency' => 'EUR'],
            // two candidates sharing a reference, to demo the NeedsReview path
            ['id' => '33333333-3333-3333-3333-333333333333', 'reference' => 'INV-1003', 'amount_minor_units' => 5000, 'currency' => 'EUR'],
            ['id' => '44444444-4444-4444-4444-444444444444', 'reference' => 'INV-1003', 'amount_minor_units' => 7500, 'currency' => 'EUR'],
        ]);
    }
}
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `expected_payments` table created.

- [ ] **Step 3: Write the failing test**

```php
<?php

use App\Modules\Matching\Domain\ExpectedPaymentCandidate;
use App\Modules\Matching\Domain\MatchingEngine;
use App\Modules\Matching\Infrastructure\Persistence\ExpectedPayment;
use App\Modules\Matching\Infrastructure\Persistence\ExpectedPaymentFinder;
use App\Modules\SharedKernel\Domain\ValueObjects\Currency;
use App\Modules\SharedKernel\Domain\ValueObjects\Money;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('finds expected payment candidates by reference', function () {
    ExpectedPayment::factory()->create(['reference' => 'INV-1', 'amount_minor_units' => 1000]);
    ExpectedPayment::factory()->create(['reference' => 'INV-2', 'amount_minor_units' => 2000]);

    $candidates = (new ExpectedPaymentFinder())->findByReference('INV-1');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->amountMinorUnits)->toBe(1000);
});

it('matches when exactly one candidate has the exact amount', function () {
    $candidates = [new ExpectedPaymentCandidate('exp-1', 1000, 'EUR', 'INV-1')];

    $outcome = (new MatchingEngine())->match(Money::fromMinorUnits(1000, Currency::EUR), $candidates);

    expect($outcome->type)->toBe('matched')
        ->and($outcome->expectedPaymentId)->toBe('exp-1');
});

it('is unmatched when there are no candidates', function () {
    $outcome = (new MatchingEngine())->match(Money::fromMinorUnits(1000, Currency::EUR), []);

    expect($outcome->type)->toBe('unmatched');
});

it('is ambiguous when candidates exist but none matches the exact amount', function () {
    $candidates = [
        new ExpectedPaymentCandidate('exp-1', 500, 'EUR', 'INV-1'),
        new ExpectedPaymentCandidate('exp-2', 750, 'EUR', 'INV-1'),
    ];

    $outcome = (new MatchingEngine())->match(Money::fromMinorUnits(1000, Currency::EUR), $candidates);

    expect($outcome->type)->toBe('ambiguous')
        ->and($outcome->candidateIds)->toBe(['exp-1', 'exp-2']);
});

it('is ambiguous when more than one candidate has the exact amount', function () {
    $candidates = [
        new ExpectedPaymentCandidate('exp-1', 1000, 'EUR', 'INV-1'),
        new ExpectedPaymentCandidate('exp-2', 1000, 'EUR', 'INV-1'),
    ];

    $outcome = (new MatchingEngine())->match(Money::fromMinorUnits(1000, Currency::EUR), $candidates);

    expect($outcome->type)->toBe('ambiguous');
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/Modules/Matching/MatchingEngineTest.php`
Expected: FAIL (classes don't exist yet)

- [ ] **Step 5: Write the implementation**

```php
<?php

namespace App\Modules\Matching\Domain;

final class ExpectedPaymentCandidate
{
    public function __construct(
        public readonly string $id,
        public readonly int $amountMinorUnits,
        public readonly string $currency,
        public readonly string $reference,
    ) {
    }
}
```

```php
<?php

namespace App\Modules\Matching\Infrastructure\Persistence;

use App\Modules\Matching\Domain\ExpectedPaymentCandidate;

final class ExpectedPaymentFinder
{
    /** @return ExpectedPaymentCandidate[] */
    public function findByReference(string $reference): array
    {
        return ExpectedPayment::where('reference', $reference)
            ->get()
            ->map(fn (ExpectedPayment $payment) => new ExpectedPaymentCandidate(
                $payment->id,
                $payment->amount_minor_units,
                $payment->currency,
                $payment->reference,
            ))
            ->all();
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain;

final class MatchOutcome
{
    /** @param string[] $candidateIds */
    private function __construct(
        public readonly string $type,
        public readonly ?string $expectedPaymentId,
        public readonly array $candidateIds,
    ) {
    }

    public static function matched(string $expectedPaymentId): self
    {
        return new self('matched', $expectedPaymentId, [$expectedPaymentId]);
    }

    public static function unmatched(): self
    {
        return new self('unmatched', null, []);
    }

    /** @param string[] $candidateIds */
    public static function ambiguous(array $candidateIds): self
    {
        return new self('ambiguous', null, $candidateIds);
    }
}
```

```php
<?php

namespace App\Modules\Matching\Domain;

use App\Modules\SharedKernel\Domain\ValueObjects\Money;

final class MatchingEngine
{
    /** @param ExpectedPaymentCandidate[] $candidates */
    public function match(Money $transactionAmount, array $candidates): MatchOutcome
    {
        $exactMatches = array_values(array_filter(
            $candidates,
            fn (ExpectedPaymentCandidate $candidate) => $candidate->amountMinorUnits === $transactionAmount->amountMinorUnits()
                && $candidate->currency === $transactionAmount->currency()->value,
        ));

        if (count($exactMatches) === 1) {
            return MatchOutcome::matched($exactMatches[0]->id);
        }

        if ($candidates === []) {
            return MatchOutcome::unmatched();
        }

        return MatchOutcome::ambiguous(array_map(fn (ExpectedPaymentCandidate $candidate) => $candidate->id, $candidates));
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Modules/Matching/MatchingEngineTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_01_000002_create_expected_payments_table.php database/factories/Modules/Matching/ExpectedPaymentFactory.php database/seeders/ExpectedPaymentSeeder.php app/Modules/Matching/Infrastructure/Persistence/ExpectedPayment.php app/Modules/Matching/Infrastructure/Persistence/ExpectedPaymentFinder.php app/Modules/Matching/Domain/ExpectedPaymentCandidate.php app/Modules/Matching/Domain/MatchOutcome.php app/Modules/Matching/Domain/MatchingEngine.php tests/Unit/Modules/Matching/MatchingEngineTest.php
git commit -m "feat(matching): add expected payment seed data and matching engine"
```

---
