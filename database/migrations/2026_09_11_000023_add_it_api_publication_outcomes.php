<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_api_requests', function (Blueprint $table): void {
            // NULL preserves legacy uncertainty: old failures cannot be assumed
            // to have rolled their domain mutation back.
            $table->string('execution_state', 20)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_api_requests')->whereNotNull('execution_state')->exists()) {
            throw new RuntimeException('API publication outcomes exist. Review recovery evidence before removing this migration.');
        }
        Schema::table('it_api_requests', function (Blueprint $table): void {
            $table->dropColumn(['execution_state', 'attempt_count', 'last_attempt_at']);
        });
    }
};
