<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversation_participants', function (Blueprint $table) {
            $table->boolean('is_archived_for_user')->default(false);
            $table->uuid('unread_marker')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->dropColumn(['is_archived_for_user', 'unread_marker']));
    }
};
