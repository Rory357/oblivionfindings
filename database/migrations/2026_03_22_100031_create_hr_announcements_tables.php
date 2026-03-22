<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->text('content');
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->string('target_audience')->default('all'); // all, department, site, role
            $table->string('target_value')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('requires_acknowledgement')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'published_at']);
            $table->index(['tenant_id', 'priority']);
        });

        Schema::create('hr_announcement_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('hr_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('acknowledged_at');
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_announcement_acknowledgements');
        Schema::dropIfExists('hr_announcements');
    }
};
