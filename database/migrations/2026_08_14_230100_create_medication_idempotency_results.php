<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // Safety-critical replays (for example medication destruction) are
            // retained indefinitely; ordinary retry results remain prunable.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['scope', 'request_uuid'],
                'medication_idempotency_scope_request_unique',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('medication_idempotency_results')
            && DB::table('medication_idempotency_results')->exists()) {
            throw new RuntimeException(
                'Cannot remove the medication idempotency replay ledger while retained request bindings exist.',
            );
        }

        Schema::dropIfExists('medication_idempotency_results');
    }
};
