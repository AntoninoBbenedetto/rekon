<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;

final class OutboxWriter
{
    /**
     * Insert an outbox row. Not transactional on its own: the caller must
     * invoke this inside the same DB::transaction() as the domain writes it
     * accompanies, so all of them commit together or none do (ADR-009).
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $messageType, array $payload, string $correlationId): void
    {
        DB::table('outbox')->insert([
            'message_type' => $messageType,
            'payload' => json_encode($payload),
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);
    }
}
