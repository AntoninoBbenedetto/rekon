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
            $transaction = (string) $request->string('action') === 'confirm'
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
