<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table): void {
            // Ownership and titles do not reliably identify an older family's
            // original recipient. Leave those conversations unclassified.
            $table->string('family_contact_kind', 24)->nullable();
            $table->foreignId('family_contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['client_id', 'chat_created_by', 'family_contact_kind'], 'chat_family_contact_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table): void {
            $table->dropIndex('chat_family_contact_lookup');
            $table->dropConstrainedForeignId('family_contact_user_id');
            $table->dropColumn('family_contact_kind');
        });
    }
};
