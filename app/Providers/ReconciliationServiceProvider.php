<?php

declare(strict_types=1);

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
