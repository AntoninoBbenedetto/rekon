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
