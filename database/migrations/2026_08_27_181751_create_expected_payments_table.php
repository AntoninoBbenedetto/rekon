<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expected_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('amount_minor_units');
            $table->string('currency');
            $table->string('reference');
            $table->timestamps();

            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_payments');
    }
};
