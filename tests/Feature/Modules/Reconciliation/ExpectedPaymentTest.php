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
