<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

final class Actor
{
    private function __construct(
        public readonly ActorType $type,
        public readonly ?string $id,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function apiCaller(string $id): self
    {
        return new self(ActorType::ApiCaller, $id);
    }
}
