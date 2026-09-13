<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_email_deliveries', function (Blueprint $table): void {
            $table->text('rfc_message_id')->nullable();
            $table->char('rfc_message_id_hash', 64)->nullable()->unique();
            $table->timestamp('rfc_message_id_recorded_at')->nullable();
        });
        // Provider transport IDs are not evidence of historical RFC headers.
    }

    public function down(): void
    {
        if (DB::table('it_email_deliveries')->whereNotNull('rfc_message_id_hash')->exists()) {
            throw new RuntimeException('Preserve outgoing message identity evidence before removing reply threading.');
        }
        Schema::table('it_email_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['rfc_message_id_hash']);
            $table->dropColumn(['rfc_message_id', 'rfc_message_id_hash', 'rfc_message_id_recorded_at']);
        });
    }
};
