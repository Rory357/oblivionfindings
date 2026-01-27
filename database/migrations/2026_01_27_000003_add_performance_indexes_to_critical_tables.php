<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Assets: index on qr_token for QR code redirects
        Schema::table('assets', function (Blueprint $table) {
            $table->index('qr_token', 'idx_assets_qr_token');
            $table->index('status', 'idx_assets_status');
            $table->index(['site_id', 'status'], 'idx_assets_site_status');
            $table->index('inspection_due_at', 'idx_assets_inspection_due');
            $table->index('maintenance_due_at', 'idx_assets_maintenance_due');
        });

        // Notifications: optimize inbox queries
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_notifiable_read');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'idx_notifications_notifiable_created');
        });

        // Client medications: frequently queried
        Schema::table('client_medications', function (Blueprint $table) {
            $table->index(['client_id', 'active'], 'idx_client_medications_client_active');
            $table->index(['client_id', 'state'], 'idx_client_medications_client_state');
        });

        // Medication administrations: for MAR queries
        // Note: client_id + administered_at already indexed as 'cma_client_admin_at_idx' in base migration
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->index(['client_id', 'scheduled_for'], 'idx_med_admin_client_scheduled');
            $table->index('status', 'idx_med_admin_status');
        });

        // Incidents: for reporting and filtering
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->index(['client_id', 'status'], 'idx_incidents_client_status');
            $table->index(['client_id', 'occurred_at'], 'idx_incidents_client_occurred');
            $table->index(['severity', 'status'], 'idx_incidents_severity_status');
            $table->index('status', 'idx_incidents_status');
        });

        // Shifts: for rostering and reporting
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['client_id', 'starts_at'], 'idx_shifts_client_starts');
            $table->index(['user_id', 'starts_at'], 'idx_shifts_user_starts');
            $table->index(['status', 'starts_at'], 'idx_shifts_status_starts');
        });

        // Timesheets: for approval workflow
        Schema::table('timesheets', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'idx_timesheets_user_status');
            $table->index(['status', 'work_date'], 'idx_timesheets_status_date');
        });

        // Audit logs: for compliance queries
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_logs_auditable');
            $table->index(['user_id', 'created_at'], 'idx_audit_logs_user_created');
            $table->index('created_at', 'idx_audit_logs_created');
        });

        // Staff credentials: for compliance dashboard
        Schema::table('staff_credentials', function (Blueprint $table) {
            $table->index(['user_id', 'expires_at'], 'idx_staff_creds_user_expires');
            $table->index('expires_at', 'idx_staff_creds_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('idx_assets_qr_token');
            $table->dropIndex('idx_assets_status');
            $table->dropIndex('idx_assets_site_status');
            $table->dropIndex('idx_assets_inspection_due');
            $table->dropIndex('idx_assets_maintenance_due');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_notifiable_read');
            $table->dropIndex('idx_notifications_notifiable_created');
        });

        Schema::table('client_medications', function (Blueprint $table) {
            $table->dropIndex('idx_client_medications_client_active');
            $table->dropIndex('idx_client_medications_client_state');
        });

        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->dropIndex('idx_med_admin_client_scheduled');
            $table->dropIndex('idx_med_admin_status');
        });

        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropIndex('idx_incidents_client_status');
            $table->dropIndex('idx_incidents_client_occurred');
            $table->dropIndex('idx_incidents_severity_status');
            $table->dropIndex('idx_incidents_status');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('idx_shifts_client_starts');
            $table->dropIndex('idx_shifts_user_starts');
            $table->dropIndex('idx_shifts_status_starts');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropIndex('idx_timesheets_user_status');
            $table->dropIndex('idx_timesheets_status_date');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_auditable');
            $table->dropIndex('idx_audit_logs_user_created');
            $table->dropIndex('idx_audit_logs_created');
        });

        Schema::table('staff_credentials', function (Blueprint $table) {
            $table->dropIndex('idx_staff_creds_user_expires');
            $table->dropIndex('idx_staff_creds_expires');
        });
    }
};
