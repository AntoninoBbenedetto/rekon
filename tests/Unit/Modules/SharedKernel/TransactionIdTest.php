<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;
use App\Modules\SharedKernel\Domain\TransactionId;
use Tests\TestCase;

uses(TestCase::class);

it('derives the same TransactionId from the same IdempotencyKey', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    $a = TransactionId::deriveFrom($key);
    $b = TransactionId::deriveFrom($key);

    expect($a->equals($b))->toBeTrue()
        ->and($a->value)->toBeString()
        ->and(strlen($a->value))->toBe(36);
});

it('derives different TransactionIds from different IdempotencyKeys', function () {
    $keyA = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $keyB = IdempotencyKey::forStatementRow('REF-2', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    expect(TransactionId::deriveFrom($keyA)->equals(TransactionId::deriveFrom($keyB)))->toBeFalse();
});

it('round-trips through fromString', function () {
    $key = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $id = TransactionId::deriveFrom($key);

    expect(TransactionId::fromString((string) $id)->equals($id))->toBeTrue();
});
