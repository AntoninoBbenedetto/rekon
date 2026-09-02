<?php

declare(strict_types=1);

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
        if ($csvContents === false) {
            throw new \RuntimeException('Unable to read the uploaded file contents.');
        }

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
