<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collision = DB::table('site_type_plans')
            ->select(['site_id', 'status'])
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'published'])
            ->groupBy('site_id', 'status')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce canonical Site plan identity while a Site has more than one current draft or published plan.',
            );
        }

        Schema::table('site_type_plans', function (Blueprint $table): void {
            $table->string('current_slot', 16)->nullable()->after('status');
        });

        DB::table('site_type_plans')
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'published'])
            ->update(['current_slot' => DB::raw('status')]);

        Schema::table('site_type_plans', function (Blueprint $table): void {
            $table->unique(['site_id', 'current_slot'], 'site_type_plans_site_current_uq');
            $table->index(['site_id', 'version'], 'site_type_plans_site_version_idx');
            $table->dropIndex('site_type_plans_tenant_id_index');
            $table->dropIndex('site_type_plans_tenant_id_site_id_index');
        });

        Schema::table('site_type_plan_pins', function (Blueprint $table): void {
            $table->index(['site_type_plan_id', 'sort_order'], 'site_type_plan_pins_plan_sort_idx');
            $table->index(['device_id', 'kind'], 'site_type_plan_pins_device_kind_idx');
            $table->dropIndex('site_type_plan_pins_tenant_id_index');
            $table->dropIndex('site_type_plan_pins_tenant_id_kind_index');
        });

        Schema::table('site_house_rooms', function (Blueprint $table): void {
            $table->index(['site_id', 'is_active', 'sort_order'], 'site_house_rooms_site_active_sort_idx');
            $table->dropIndex('site_house_rooms_tenant_id_index');
        });

        Schema::table('site_house_room_history', function (Blueprint $table): void {
            $table->index(['room_id', 'assigned_until', 'assigned_from'], 'site_room_history_open_dates_idx');
            $table->dropIndex('site_house_room_history_tenant_id_index');
        });

        Schema::table('site_ho_resources', function (Blueprint $table): void {
            $table->index(['site_id', 'is_active', 'name'], 'site_ho_resources_site_active_name_idx');
            $table->dropIndex('site_ho_resources_tenant_id_index');
        });

        Schema::table('site_facility_zones', function (Blueprint $table): void {
            $table->index(['site_id', 'is_active', 'name'], 'site_facility_zones_site_active_name_idx');
            $table->dropIndex('site_facility_zones_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('site_facility_zones', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_facility_zones_tenant_id_index');
            $table->dropIndex('site_facility_zones_site_active_name_idx');
        });

        Schema::table('site_ho_resources', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_ho_resources_tenant_id_index');
            $table->dropIndex('site_ho_resources_site_active_name_idx');
        });

        Schema::table('site_house_room_history', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_house_room_history_tenant_id_index');
            $table->dropIndex('site_room_history_open_dates_idx');
        });

        Schema::table('site_house_rooms', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_house_rooms_tenant_id_index');
            $table->dropIndex('site_house_rooms_site_active_sort_idx');
        });

        Schema::table('site_type_plan_pins', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_type_plan_pins_tenant_id_index');
            $table->index(['tenant_id', 'kind'], 'site_type_plan_pins_tenant_id_kind_index');
            $table->dropIndex('site_type_plan_pins_plan_sort_idx');
            $table->dropIndex('site_type_plan_pins_device_kind_idx');
        });

        Schema::table('site_type_plans', function (Blueprint $table): void {
            $table->index('tenant_id', 'site_type_plans_tenant_id_index');
            $table->index(['tenant_id', 'site_id'], 'site_type_plans_tenant_id_site_id_index');
            $table->dropUnique('site_type_plans_site_current_uq');
            $table->dropIndex('site_type_plans_site_version_idx');
            $table->dropColumn('current_slot');
        });
    }
};
