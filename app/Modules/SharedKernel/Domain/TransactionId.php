<?php

declare(strict_types=1);

namespace App\Modules\SharedKernel\Domain;

use Ramsey\Uuid\Uuid;

final class TransactionId
{
    private function __construct(public readonly string $value)
    {
    }

    public static function deriveFrom(IdempotencyKey $key): self
    {
        $namespace = config('reconciliation.transaction_id_namespace');

        return new self(Uuid::uuid5($namespace, $key->value)->toString());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(TransactionId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
