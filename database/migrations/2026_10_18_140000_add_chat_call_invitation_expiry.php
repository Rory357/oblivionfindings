<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_call_participants', function (Blueprint $table): void {
            $table->timestamp('invitation_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_call_participants', function (Blueprint $table): void {
            $table->dropColumn('invitation_expires_at');
        });
    }
};
