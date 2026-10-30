<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table): void {
            $table->boolean('is_managed_channel')->default(false);
            $table->foreignId('channel_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('chat_created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('creation_mutation_id')->nullable();
            $table->unique(['chat_created_by', 'creation_mutation_id'], 'chat_creation_retry_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ops_conversations', function (Blueprint $table): void {
            $table->dropUnique('chat_creation_retry_unique');
            $table->dropConstrainedForeignId('channel_site_id');
            $table->dropConstrainedForeignId('chat_created_by');
            $table->dropColumn(['is_managed_channel', 'creation_mutation_id']);
        });
    }
};
