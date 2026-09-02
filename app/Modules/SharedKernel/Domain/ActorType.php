<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

enum ActorType: string
{
    case System = 'system';
    case ApiCaller = 'api_caller';
}
