<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_feed_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('post_type'); // update, milestone, kudos, announcement
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'post_type', 'created_at']);
        });

        Schema::create('hr_kudos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category'); // teamwork, innovation, leadership, customer_focus, going_above, other
            $table->text('message');
            $table->boolean('is_public')->default(true);
            $table->foreignId('feed_post_id')->nullable()->constrained('hr_feed_posts')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_kudos');
        Schema::dropIfExists('hr_feed_posts');
    }
};
