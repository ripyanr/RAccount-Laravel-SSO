<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raccount_webhook_events', function (Blueprint $table): void {
            $table->ulid('event_id')->primary();
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('received_at', 3);
            $table->timestamp('processed_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raccount_webhook_events');
    }
};
