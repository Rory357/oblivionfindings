<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_interactions', function (Blueprint $table): void {
            $table->timestamp('author_removed_at')->nullable();
            $table->timestamp('moderator_hidden_at')->nullable();
            $table->foreignId('moderator_hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('parent_hidden_at')->nullable();
            $table->timestamp('legacy_hidden_at')->nullable();
            $table->unsignedInteger('moderation_version')->default(1);
        });

        // Old cascades overwrote independent hiding. Never guess that these are safe to restore.
        DB::table('hub_interactions')->where('kind', 'comment')->whereNotNull('hidden_at')
            ->update(['legacy_hidden_at' => DB::raw('hidden_at')]);
    }

    public function down(): void
    {
        Schema::table('hub_interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('moderator_hidden_by');
            $table->dropColumn(['author_removed_at', 'moderator_hidden_at', 'parent_hidden_at', 'legacy_hidden_at', 'moderation_version']);
        });
    }
};
