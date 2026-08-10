<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $categoryCollision = DB::table('hr_calendar_event_categories')
            ->select('key', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('key')
            ->first();
        if ($categoryCollision !== null) {
            throw new RuntimeException(
                'Cannot enforce application calendar-category identity: duplicate keys exist.',
            );
        }

        $exceptionCollision = DB::table('hr_calendar_events')
            ->select('recurrence_parent_id', 'exception_date', DB::raw('COUNT(*) AS duplicate_count'))
            ->whereNotNull('recurrence_parent_id')
            ->whereNotNull('exception_date')
            ->groupBy('recurrence_parent_id', 'exception_date')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($exceptionCollision !== null) {
            throw new RuntimeException(
                'Cannot enforce recurring calendar exception identity: duplicate occurrence overrides exist.',
            );
        }

        Schema::table('hr_calendar_event_categories', function (Blueprint $table): void {
            $table->dropIndex('hr_calendar_event_categories_tenant_id_index');
            $table->dropUnique('hr_calendar_event_categories_tenant_id_key_unique');

            $table->unique('key', 'hr_calendar_event_categories_key_uq');
            $table->index(['sort', 'label'], 'hr_calendar_categories_sort_label_idx');
        });

        Schema::table('hr_calendar_events', function (Blueprint $table): void {
            $table->dropIndex('hr_calendar_events_tenant_id_index');
            $table->dropIndex('hr_calendar_events_tenant_id_starts_at_ends_at_index');

            $table->index(
                ['archived_at', 'starts_at', 'ends_at'],
                'hr_calendar_events_active_range_idx',
            );
            $table->index(['site_id', 'starts_at'], 'hr_calendar_events_site_start_idx');
            $table->unique(
                ['recurrence_parent_id', 'exception_date'],
                'hr_calendar_events_parent_exception_uq',
            );
        });

        Schema::table('hr_calendar_event_attachments', function (Blueprint $table): void {
            $table->dropIndex('hr_calendar_event_attachments_tenant_id_index');
            $table->index(['event_id', 'created_at'], 'hr_calendar_attach_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_calendar_event_attachments', function (Blueprint $table): void {
            $table->dropIndex('hr_calendar_attach_event_created_idx');
            $table->index('tenant_id', 'hr_calendar_event_attachments_tenant_id_index');
        });

        Schema::table('hr_calendar_events', function (Blueprint $table): void {
            $table->dropIndex('hr_calendar_events_active_range_idx');
            $table->dropIndex('hr_calendar_events_site_start_idx');
            $table->dropUnique('hr_calendar_events_parent_exception_uq');

            $table->index('tenant_id', 'hr_calendar_events_tenant_id_index');
            $table->index(
                ['tenant_id', 'starts_at', 'ends_at'],
                'hr_calendar_events_tenant_id_starts_at_ends_at_index',
            );
        });

        Schema::table('hr_calendar_event_categories', function (Blueprint $table): void {
            $table->dropUnique('hr_calendar_event_categories_key_uq');
            $table->dropIndex('hr_calendar_categories_sort_label_idx');

            $table->index('tenant_id', 'hr_calendar_event_categories_tenant_id_index');
            $table->unique(
                ['tenant_id', 'key'],
                'hr_calendar_event_categories_tenant_id_key_unique',
            );
        });
    }
};
