<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->string('quarantine_reason', 100)->nullable()->after('status');
            $table->index(['status', 'received_at'], 'it_inbound_emails_status_received_idx');
        });
    }

    public function down(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table): void {
            $table->dropIndex('it_inbound_emails_status_received_idx');
            $table->dropColumn('quarantine_reason');
        });
    }
};
