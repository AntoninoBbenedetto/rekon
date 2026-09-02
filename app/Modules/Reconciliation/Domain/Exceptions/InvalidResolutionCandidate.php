<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Exceptions;

use RuntimeException;

final class InvalidResolutionCandidate extends RuntimeException
{
    public function __construct(public readonly string $expectedPaymentId)
    {
        parent::__construct("{$expectedPaymentId} is not among the recorded candidates for this transaction.");
    }
}
