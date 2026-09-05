<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->string('message_type');
            $table->jsonb('payload');
            $table->uuid('correlation_id');
            $table->timestampTz('created_at');

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
