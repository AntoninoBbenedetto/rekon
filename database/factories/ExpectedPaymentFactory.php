<?php

namespace Database\Factories;

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpectedPayment> */
class ExpectedPaymentFactory extends Factory
{
    protected $model = ExpectedPayment::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'amount_minor_units' => $this->faker->numberBetween(1000, 100000),
            'currency' => 'EUR',
            'reference' => strtoupper('REF-' . $this->faker->unique()->numberBetween(1000, 9999)),
        ];
    }
}
