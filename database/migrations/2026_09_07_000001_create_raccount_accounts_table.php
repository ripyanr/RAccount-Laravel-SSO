<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raccount_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('raccount_sub')->unique();
            $table->string('user_type');
            $table->string('user_id');
            $table->index(['user_type', 'user_id']);
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('picture_url')->nullable();
            $table->json('scopes')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at', 3)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_login_at', 3)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raccount_accounts');
    }
};
