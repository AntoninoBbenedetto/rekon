<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_read_model', function (Blueprint $table) {
            $table->uuid('transaction_id')->primary();
            $table->string('state');
            $table->unsignedInteger('version');
            $table->bigInteger('amount_minor_units');
            $table->string('currency');
            $table->string('reference');
            $table->date('statement_date');
            $table->uuid('matched_expected_payment_id')->nullable();
            $table->timestampTz('imported_at');
            $table->timestampTz('updated_at');

            $table->index('state');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_read_model');
    }
};
