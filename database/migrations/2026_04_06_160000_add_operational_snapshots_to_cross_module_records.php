<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'shift_site_id')) {
                $table->foreignId('shift_site_id')->nullable()->after('shift_id')->constrained('sites')->nullOnDelete();
            }
            if (! Schema::hasColumn('timesheets', 'shift_service_context_id')) {
                $table->foreignId('shift_service_context_id')->nullable()->after('shift_site_id')->constrained('service_contexts')->nullOnDelete();
            }
            if (! Schema::hasColumn('timesheets', 'shift_site_name_snapshot')) {
                $table->string('shift_site_name_snapshot')->nullable()->after('shift_service_context_id');
            }
            if (! Schema::hasColumn('timesheets', 'shift_location_snapshot')) {
                $table->string('shift_location_snapshot')->nullable()->after('shift_site_name_snapshot');
            }
            if (! Schema::hasColumn('timesheets', 'service_context_name_snapshot')) {
                $table->string('service_context_name_snapshot')->nullable()->after('shift_location_snapshot');
            }
            if (! Schema::hasColumn('timesheets', 'client_name_snapshot')) {
                $table->string('client_name_snapshot')->nullable()->after('service_context_name_snapshot');
            }
            if (! Schema::hasColumn('timesheets', 'staff_name_snapshot')) {
                $table->string('staff_name_snapshot')->nullable()->after('client_name_snapshot');
            }
            if (! Schema::hasColumn('timesheets', 'shift_type_snapshot')) {
                $table->string('shift_type_snapshot')->nullable()->after('staff_name_snapshot');
            }
            if (! Schema::hasColumn('timesheets', 'coverage_roles_snapshot')) {
                $table->json('coverage_roles_snapshot')->nullable()->after('shift_type_snapshot');
            }
        });

        Schema::table('billing_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_entries', 'site_id')) {
                $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->nullOnDelete();
            }
            if (! Schema::hasColumn('billing_entries', 'site_name_snapshot')) {
                $table->string('site_name_snapshot')->nullable()->after('site_id');
            }
            if (! Schema::hasColumn('billing_entries', 'location_snapshot')) {
                $table->string('location_snapshot')->nullable()->after('site_name_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'service_context_name_snapshot')) {
                $table->string('service_context_name_snapshot')->nullable()->after('location_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'client_name_snapshot')) {
                $table->string('client_name_snapshot')->nullable()->after('service_context_name_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'staff_name_snapshot')) {
                $table->string('staff_name_snapshot')->nullable()->after('client_name_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'shift_type_snapshot')) {
                $table->string('shift_type_snapshot')->nullable()->after('staff_name_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'pay_type_snapshot')) {
                $table->string('pay_type_snapshot')->nullable()->after('shift_type_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'pay_rate_snapshot')) {
                $table->decimal('pay_rate_snapshot', 10, 2)->nullable()->after('pay_type_snapshot');
            }
            if (! Schema::hasColumn('billing_entries', 'payroll_cost')) {
                $table->decimal('payroll_cost', 10, 2)->nullable()->after('pay_rate_snapshot');
            }
        });

        Schema::table('fleet_resident_transports', function (Blueprint $table) {
            if (! Schema::hasColumn('fleet_resident_transports', 'site_id')) {
                $table->foreignId('site_id')->nullable()->after('shift_id')->constrained('sites')->nullOnDelete();
            }
            if (! Schema::hasColumn('fleet_resident_transports', 'site_name_snapshot')) {
                $table->string('site_name_snapshot')->nullable()->after('site_id');
            }
            if (! Schema::hasColumn('fleet_resident_transports', 'shift_location_snapshot')) {
                $table->string('shift_location_snapshot')->nullable()->after('site_name_snapshot');
            }
            if (! Schema::hasColumn('fleet_resident_transports', 'service_context_name_snapshot')) {
                $table->string('service_context_name_snapshot')->nullable()->after('shift_location_snapshot');
            }
            if (! Schema::hasColumn('fleet_resident_transports', 'driver_name_snapshot')) {
                $table->string('driver_name_snapshot')->nullable()->after('service_context_name_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleet_resident_transports', function (Blueprint $table) {
            foreach (['site_id', 'site_name_snapshot', 'shift_location_snapshot', 'service_context_name_snapshot', 'driver_name_snapshot'] as $column) {
                if (! Schema::hasColumn('fleet_resident_transports', $column)) {
                    continue;
                }

                if ($column === 'site_id') {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('billing_entries', function (Blueprint $table) {
            foreach (['site_id', 'site_name_snapshot', 'location_snapshot', 'service_context_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot', 'pay_type_snapshot', 'pay_rate_snapshot', 'payroll_cost'] as $column) {
                if (! Schema::hasColumn('billing_entries', $column)) {
                    continue;
                }

                if ($column === 'site_id') {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('timesheets', function (Blueprint $table) {
            foreach (['shift_site_id', 'shift_service_context_id', 'shift_site_name_snapshot', 'shift_location_snapshot', 'service_context_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot', 'coverage_roles_snapshot'] as $column) {
                if (! Schema::hasColumn('timesheets', $column)) {
                    continue;
                }

                if (in_array($column, ['shift_site_id', 'shift_service_context_id'], true)) {
                    $table->dropConstrainedForeignId($column);
                } else {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
