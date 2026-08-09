<?php

namespace App\Modules\SharedKernel\Domain;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
}
