<?php

return [
    // Fixed application namespace used to derive deterministic Transaction
    // aggregate ids from their IdempotencyKey (ADR-006). Never change this
    // after v1 ships: doing so would change every derived TransactionId.
    'transaction_id_namespace' => env('RECONCILIATION_TRANSACTION_ID_NAMESPACE', 'fe04f55c-d438-4630-a660-dc8d6afb6672'),
];
