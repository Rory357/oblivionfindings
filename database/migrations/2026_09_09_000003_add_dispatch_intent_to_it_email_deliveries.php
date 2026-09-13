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
            $table->timestamp('dispatch_requested_at')->nullable();
            $table->timestamp('dispatch_finished_at')->nullable();
            $table->index(['dispatch_finished_at', 'dispatch_requested_at'], 'it_delivery_pending_dispatch');
        });
    }

    public function down(): void
    {
        if (DB::table('it_email_deliveries')->whereNotNull('dispatch_requested_at')->exists()) {
            throw new RuntimeException('Retain durable notification intents. Use a forward repair instead of removing dispatch history.');
        }
        Schema::table('it_email_deliveries', function (Blueprint $table): void {
            $table->dropIndex('it_delivery_pending_dispatch');
            $table->dropColumn(['dispatch_requested_at', 'dispatch_finished_at']);
        });
    }
};
