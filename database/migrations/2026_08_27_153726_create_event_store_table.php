<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_store', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_id');
            $table->unsignedInteger('version');
            $table->string('event_type');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->uuid('causation_id');
            $table->uuid('correlation_id');
            $table->timestampTz('recorded_at');

            $table->unique(['aggregate_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_store');
    }
};
