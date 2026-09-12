<?php

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Domain\AbstractDomainEvent;
use App\Modules\SharedKernel\Domain\AggregateRoot;
use App\Modules\SharedKernel\Domain\DomainEvent;

arch('SharedKernel does not depend on Reconciliation')
    ->expect('App\Modules\SharedKernel')
    ->not->toUse('App\Modules\Reconciliation');

arch('Reconciliation Domain does not depend on its own Application or Infrastructure layer')
    ->expect('App\Modules\Reconciliation\Domain')
    ->not->toUse([
        'App\Modules\Reconciliation\Application',
        'App\Modules\Reconciliation\Infrastructure',
    ]);

arch('SharedKernel Domain does not depend on its own Infrastructure layer')
    ->expect('App\Modules\SharedKernel\Domain')
    ->not->toUse('App\Modules\SharedKernel\Infrastructure')
    ->ignoring(DomainEvent::class);
// DomainEvent::fromStoredRow() takes a StoredEventRow (Infrastructure) by design,
// so events can be reconstructed from the event store — see ADR-006/ADR-003.

arch('Reconciliation Domain does not depend on the framework')
    ->expect('App\Modules\Reconciliation\Domain')
    ->not->toUse('Illuminate')
    ->ignoring(ExpectedPayment::class);
// ExpectedPayment is a plain Eloquent model by design (Architecture Principle #6:
// event sourcing is a tool for the Transaction aggregate, not a house style).

arch('SharedKernel Domain does not depend on the framework')
    ->expect('App\Modules\SharedKernel\Domain')
    ->not->toUse('Illuminate');

arch('domain classes are final')
    ->expect('App\Modules')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        AggregateRoot::class,
        AbstractDomainEvent::class,
        ExpectedPayment::class,
        TransactionProjection::class,
    ]);
