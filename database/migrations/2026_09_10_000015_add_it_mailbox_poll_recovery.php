<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_mailbox_connections', function (Blueprint $table): void {
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamp('last_poll_attempt_at')->nullable();
            $table->string('last_poll_failure_code', 40)->nullable();
            $table->unsignedInteger('consecutive_poll_failures')->default(0);
            $table->timestamp('next_poll_at')->nullable();
            $table->uuid('poll_claim_token')->nullable();
            $table->timestamp('poll_claim_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('it_mailbox_connections', fn (Blueprint $table) => $table->dropColumn([
            'configuration_version', 'last_poll_attempt_at', 'last_poll_failure_code',
            'consecutive_poll_failures', 'next_poll_at', 'poll_claim_token', 'poll_claim_expires_at',
        ]));
    }
};
