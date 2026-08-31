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
