<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic reactions + replies for the community wall's non-kudos items —
 * announcements and update/question/win posts — so every card can be reacted to
 * and discussed, matching the design comp. Kudos keep their own
 * `hr_kudos_reactions`/`hr_kudos_replies` (shared with /hr/my/shoutouts), so this
 * store covers the disjoint set: subject_type is `post` (HrFeedPost) or
 * `announcement` (HrAnnouncement).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_feed_reactions')) {
            Schema::create('hr_feed_reactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('subject_type', 32); // post | announcement
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('emoji', 16); // heart | party | hands
                $table->timestamps();

                $table->unique(['subject_type', 'subject_id', 'user_id', 'emoji'], 'hr_feed_reactions_unique');
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('hr_feed_replies')) {
            Schema::create('hr_feed_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('subject_type', 32);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamps();

                $table->index(['subject_type', 'subject_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_feed_replies');
        Schema::dropIfExists('hr_feed_reactions');
    }
};
