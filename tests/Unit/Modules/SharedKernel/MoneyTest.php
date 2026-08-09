<?php

use App\Modules\SharedKernel\Domain\Currency;
use App\Modules\SharedKernel\Domain\Money;

it('exposes amount and currency', function () {
    $money = new Money(12345, Currency::EUR);

    expect($money->amountMinorUnits)->toBe(12345)
        ->and($money->currency)->toBe(Currency::EUR);
});

it('rejects a negative amount', function () {
    new Money(-1, Currency::EUR);
})->throws(InvalidArgumentException::class);

it('considers two Money equal when amount and currency match', function () {
    $a = new Money(500, Currency::EUR);
    $b = new Money(500, Currency::EUR);
    $c = new Money(500, Currency::USD);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
