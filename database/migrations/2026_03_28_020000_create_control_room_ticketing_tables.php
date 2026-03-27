<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_room_alert_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open');
            $table->string('priority')->default('medium');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('parent_task_id')->nullable()->constrained('control_room_alert_tasks')->nullOnDelete();
            $table->timestamps();
            $table->index(['alert_id', 'status']);
            $table->index(['assigned_to_user_id', 'status']);
        });

        Schema::create('control_room_alert_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('control_room_alert_discussions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('type')->default('comment');
            $table->text('content');
            $table->boolean('is_internal')->default(true);
            $table->json('attachments')->nullable();
            $table->json('mentions')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->index(['alert_id', 'created_at']);
            $table->index(['parent_id']);
        });

        Schema::create('control_room_alert_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['alert_id', 'user_id']);
        });

        Schema::create('control_room_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('control_room_alert_tasks')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('description')->nullable();
            $table->boolean('billable')->default(false);
            $table->timestamps();
            $table->index(['alert_id', 'user_id']);
        });

        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->string('priority')->nullable()->after('severity');
            $table->timestamp('due_at')->nullable()->after('closed_at');
            $table->string('category')->nullable()->after('alert_type');
            $table->string('resolution_code')->nullable()->after('notes');
            $table->unsignedInteger('time_spent_minutes')->default(0)->after('resolution_code');
            $table->unsignedInteger('watchers_count')->default(0)->after('time_spent_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->dropColumn([
                'priority',
                'due_at',
                'category',
                'resolution_code',
                'time_spent_minutes',
                'watchers_count',
            ]);
        });

        Schema::dropIfExists('control_room_time_entries');
        Schema::dropIfExists('control_room_alert_watchers');
        Schema::dropIfExists('control_room_alert_discussions');
        Schema::dropIfExists('control_room_alert_tasks');
    }
};
