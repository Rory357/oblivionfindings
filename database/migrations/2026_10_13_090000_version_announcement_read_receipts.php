<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table) {
            $table->unsignedInteger('read_version')->default(1);
            $table->boolean('push_to_bell')->default(false);
            $table->foreignId('recurrence_source_id')->nullable()->unique()->constrained('hr_announcements')->nullOnDelete();
        });
        DB::table('hr_announcements')->whereNotNull('inbox_announcement_id')->update(['push_to_bell' => true]);

        Schema::table('hr_announcement_acknowledgements', function (Blueprint $table) {
            // Historical receipts have no provable content version. Keep them,
            // but do not count them as confirmation of today's content.
            $table->unsignedInteger('read_version')->default(0);
            $table->unique(['announcement_id', 'user_id', 'read_version'], 'hr_announcement_ack_version_unique');
            $table->dropUnique('hr_announcement_acknowledgements_announcement_id_user_id_unique');
        });

        Schema::create('hr_announcement_read_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('hr_announcements')->cascadeOnDelete();
            $table->unsignedInteger('read_version');
            $table->json('content');
            $table->timestamp('created_at');
            $table->unique(['announcement_id', 'read_version'], 'hr_announcement_read_version_unique');
        });
    }

    public function down(): void
    {
        // Collapsing several versioned receipts into one would destroy evidence.
        throw new RuntimeException('Announcement receipt history requires an explicit data-preserving rollback.');
    }
};
