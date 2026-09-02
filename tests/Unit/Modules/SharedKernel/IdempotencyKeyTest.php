<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\IdempotencyKey;

it('derives the same key from the same normalized content', function () {
    $a = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $b = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    expect($a->equals($b))->toBeTrue();
});

it('derives a different key when occurrence_index differs', function () {
    $a = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $b = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 1);

    expect($a->equals($b))->toBeFalse();
});

it('derives a different key when any of the other four fields differ', function () {
    $base = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);

    $differentReference = IdempotencyKey::forStatementRow('REF-2', 12345, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $differentAmount = IdempotencyKey::forStatementRow('REF-1', 99999, Currency::EUR, new DateTimeImmutable('2026-07-31'), 0);
    $differentCurrency = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::USD, new DateTimeImmutable('2026-07-31'), 0);
    $differentDate = IdempotencyKey::forStatementRow('REF-1', 12345, Currency::EUR, new DateTimeImmutable('2026-08-01'), 0);

    expect($base->equals($differentReference))->toBeFalse()
        ->and($base->equals($differentAmount))->toBeFalse()
        ->and($base->equals($differentCurrency))->toBeFalse()
        ->and($base->equals($differentDate))->toBeFalse();
});
