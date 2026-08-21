<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_idempotency_results', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 120);
            $table->uuid('request_uuid');
            $table->json('response_payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(
                ['scope', 'request_uuid'],
                'medication_idempotency_scope_request_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_idempotency_results');
    }
};
