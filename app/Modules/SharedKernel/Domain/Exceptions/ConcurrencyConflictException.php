<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class ConcurrencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $attemptedVersion,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Concurrency conflict on aggregate {$aggregateId} at version {$attemptedVersion}.",
            previous: $previous,
        );
    }
}
