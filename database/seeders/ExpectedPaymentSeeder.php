<?php

namespace Database\Seeders;

use App\Modules\Reconciliation\Domain\ExpectedPayment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpectedPaymentSeeder extends Seeder
{
    /**
     * Seeds expected payments covering every outcome of MatchTransactionService,
     * lined up against docs/api/sample-import.csv (verified end-to-end via
     * `reconciliation:relay-outbox` + `queue:work`):
     * - REF-SAMPLE-1: one candidate, amount+currency match -> auto-reconciled (Reconciled).
     * - REF-SAMPLE-2: one candidate, amount mismatch -> NeedsReview (partial_amount_match).
     * - REF-SAMPLE-3: two candidates for the same reference -> NeedsReview (multiple_candidates).
     * - REF-SAMPLE-4 is left without a candidate: its transaction stays Unmatched.
     */
    public function run(): void
    {
        ExpectedPayment::query()->firstOrCreate(
            ['reference' => 'REF-SAMPLE-1', 'amount_minor_units' => 150000, 'currency' => 'EUR'],
            ['id' => (string) Str::uuid()],
        );

        ExpectedPayment::query()->firstOrCreate(
            ['reference' => 'REF-SAMPLE-2', 'amount_minor_units' => 6000, 'currency' => 'USD'],
            ['id' => (string) Str::uuid()],
        );

        ExpectedPayment::query()->firstOrCreate(
            ['reference' => 'REF-SAMPLE-3', 'amount_minor_units' => 25000, 'currency' => 'EUR'],
            ['id' => (string) Str::uuid()],
        );
        ExpectedPayment::query()->firstOrCreate(
            ['reference' => 'REF-SAMPLE-3', 'amount_minor_units' => 30000, 'currency' => 'EUR'],
            ['id' => (string) Str::uuid()],
        );
    }
}
