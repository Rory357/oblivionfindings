<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_kudos_replies', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();
            $table->timestamp('author_removed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('hub_comment_appreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('hub_interactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['comment_id', 'user_id'], 'hub_comment_appreciations_comment_user_unique');
        });
    }

    public function down(): void
    {
        // Never discard removal provenance or invent text for an erased reply.
        if (DB::table('hr_kudos_replies')->whereNotNull('author_removed_at')->orWhereNull('body')->exists()
            || DB::table('hub_comment_appreciations')->exists()) {
            throw new RuntimeException('Reply removal or comment appreciation records require a reviewed rollback.');
        }

        Schema::drop('hub_comment_appreciations');
        Schema::table('hr_kudos_replies', function (Blueprint $table): void {
            $table->dropColumn(['author_removed_at', 'version']);
            $table->text('body')->nullable(false)->change();
        });
    }
};
