<?php

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Domain\Transaction;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ListTransactionsRequest;
use App\Modules\Reconciliation\Infrastructure\Http\TransactionDetailPresenter;
use App\Modules\Reconciliation\Infrastructure\Persistence\TransactionProjection;
use App\Modules\SharedKernel\Application\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class TransactionsController extends Controller
{
    public function index(ListTransactionsRequest $request): JsonResponse
    {
        $query = TransactionProjection::query();

        if ($request->filled('state')) {
            $query->where('state', (string) $request->string('state'));
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

        return response()->json(TransactionDetailPresenter::toPayload($transaction, $events));
    }
}
