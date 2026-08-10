<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table): void {
            $table->dropIndex('hr_announcements_tenant_id_index');
            $table->dropIndex('hr_announcements_tenant_id_published_at_index');
            $table->dropIndex('hr_announcements_tenant_id_priority_index');

            $table->index(
                ['status', 'published_at'],
                'hr_announcements_status_published_idx',
            );
            $table->index(
                ['is_pinned', 'status', 'published_at'],
                'hr_announcements_pinned_status_published_idx',
            );
            $table->index(
                ['priority', 'status', 'published_at'],
                'hr_announcements_priority_status_published_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table): void {
            $table->dropIndex('hr_announcements_status_published_idx');
            $table->dropIndex('hr_announcements_pinned_status_published_idx');
            $table->dropIndex('hr_announcements_priority_status_published_idx');

            $table->index('tenant_id', 'hr_announcements_tenant_id_index');
            $table->index(
                ['tenant_id', 'published_at'],
                'hr_announcements_tenant_id_published_at_index',
            );
            $table->index(
                ['tenant_id', 'priority'],
                'hr_announcements_tenant_id_priority_index',
            );
        });
    }
};
