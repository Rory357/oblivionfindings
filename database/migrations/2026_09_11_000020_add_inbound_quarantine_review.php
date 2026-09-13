<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_inbound_emails', function (Blueprint $table) {
            $table->unsignedInteger('quarantine_review_version')->default(0);
            $table->timestamp('quarantine_retry_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_inbound_emails')->where('quarantine_review_version', '>', 0)->exists()) {
            throw new RuntimeException('Preserve quarantine review and recovery evidence before removing its metadata.');
        }
        Schema::table('it_inbound_emails', fn (Blueprint $table) => $table->dropColumn([
            'quarantine_review_version', 'quarantine_retry_requested_at',
        ]));
    }
};
