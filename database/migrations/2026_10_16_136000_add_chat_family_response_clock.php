<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table) {
            // No inferred deadline for legacy conversations without a known response cycle.
            $table->timestamp('family_response_started_at')->nullable();
            $table->unsignedBigInteger('family_response_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops_conversations', fn (Blueprint $table) => $table->dropColumn(['family_response_started_at', 'family_response_message_id']));
    }
};
