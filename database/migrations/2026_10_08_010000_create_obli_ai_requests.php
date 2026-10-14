<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obli_ai_budget_periods', function (Blueprint $table): void {
            $table->string('period', 24)->primary();
            $table->unsignedBigInteger('committed_micros')->default(0);
        });
        Schema::create('obli_ai_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_uuid');
            $table->string('request_hash', 64);
            $table->unsignedInteger('configuration_version');
            $table->string('provider', 16);
            $table->string('model', 120);
            $table->string('status', 16);
            $table->json('periods');
            $table->unsignedBigInteger('reserved_micros');
            $table->unsignedBigInteger('charged_micros')->nullable();
            $table->unsignedBigInteger('input_rate');
            $table->unsignedBigInteger('output_rate');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->longText('answer_encrypted')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
            $table->unique(['user_id', 'request_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obli_ai_requests');
        Schema::dropIfExists('obli_ai_budget_periods');
    }
};
