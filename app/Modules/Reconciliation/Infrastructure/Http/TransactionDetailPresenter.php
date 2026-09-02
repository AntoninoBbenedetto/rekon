<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\SharedKernel\Domain\DomainEvent;

final class TransactionDetailPresenter
{
    /**
     * @param  DomainEvent[]  $events
     * @return array<string, mixed>
     */
    public static function toPayload(Transaction $transaction, array $events): array
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
