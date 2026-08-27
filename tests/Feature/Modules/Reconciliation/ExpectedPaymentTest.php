<?php

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an expected payment via its factory with a uuid id', function () {
    $payment = ExpectedPayment::factory()->create([
        'reference' => 'REF-1',
        'amount_minor_units' => 12345,
        'currency' => 'EUR',
    ]);

    expect($payment->id)->toBeString()
        ->and(strlen($payment->id))->toBe(36)
        ->and(ExpectedPayment::query()->where('reference', 'REF-1')->count())->toBe(1);
});

it('casts amount_minor_units to integer from database', function () {
    // Create payment via factory with numeric value
    $payment = ExpectedPayment::factory()->create([
        'amount_minor_units' => 12345,
        'reference' => 'REF-CAST-TEST',
    ]);

    // Fetch fresh from database to verify cast is applied on retrieval
    $fresh = ExpectedPayment::query()->where('reference', 'REF-CAST-TEST')->first();

    // Verify that amount_minor_units is an integer, not a string
    // (Postgres bigint can return as string without proper casting)
    expect($fresh->amount_minor_units)->toBeInt()
        ->and($fresh->amount_minor_units)->toBe(12345)
        ->and(is_int($fresh->amount_minor_units))->toBeTrue()
        // Verify strict equality works (critical for Money::equals() which uses ===)
        ->and($fresh->amount_minor_units === 12345)->toBeTrue()
        ->and($fresh->amount_minor_units === '12345')->toBeFalse();
});
