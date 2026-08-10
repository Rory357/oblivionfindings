<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_report_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('hr_report_subscriptions_tenant_id_index');
            $table->dropIndex('hr_report_sub_active_next_idx');
            $table->dropIndex('hr_report_sub_tenant_type_idx');

            $table->index(['is_active', 'next_run_at'], 'hr_report_sub_active_next_app_idx');
            $table->index(['report_type', 'is_active'], 'hr_report_sub_type_active_idx');
        });

        Schema::table('hr_report_exports', function (Blueprint $table): void {
            $table->dropIndex('hr_report_exports_tenant_id_index');
            $table->dropIndex('hr_report_export_tenant_generated_idx');

            $table->index(['report_type', 'generated_at'], 'hr_report_export_type_generated_idx');
            $table->index(['subscription_id', 'generated_at'], 'hr_report_export_subscription_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_report_exports', function (Blueprint $table): void {
            $table->dropIndex('hr_report_export_type_generated_idx');
            $table->dropIndex('hr_report_export_subscription_generated_idx');

            $table->index('tenant_id', 'hr_report_exports_tenant_id_index');
            $table->index(['tenant_id', 'generated_at'], 'hr_report_export_tenant_generated_idx');
        });

        Schema::table('hr_report_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('hr_report_sub_active_next_app_idx');
            $table->dropIndex('hr_report_sub_type_active_idx');

            $table->index('tenant_id', 'hr_report_subscriptions_tenant_id_index');
            $table->index(['tenant_id', 'is_active', 'next_run_at'], 'hr_report_sub_active_next_idx');
            $table->index(['tenant_id', 'report_type'], 'hr_report_sub_tenant_type_idx');
        });
    }
};
