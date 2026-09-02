<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure;

use RuntimeException;

final class MalformedStatementException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The statement file is structurally invalid: '.implode(' ', $errors));
    }
}
