<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_push_subscriptions', function (Blueprint $table): void {
            // Existing registrations must explicitly verify their current browser worker.
            $table->unsignedSmallInteger('chat_protocol_version')->default(0);
            $table->uuid('chat_registration_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_push_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['chat_protocol_version', 'chat_registration_id']);
        });
    }
};
