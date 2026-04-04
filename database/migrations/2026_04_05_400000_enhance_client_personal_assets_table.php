<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table) {
            if (!Schema::hasColumn('client_personal_assets', 'status')) {
                $table->string('status')->default('active')->after('notes');
            }
            if (!Schema::hasColumn('client_personal_assets', 'ownership')) {
                $table->string('ownership')->default('client')->after('status');
            }
            if (!Schema::hasColumn('client_personal_assets', 'funding_source')) {
                $table->string('funding_source')->nullable()->after('ownership');
            }
            if (!Schema::hasColumn('client_personal_assets', 'return_required')) {
                $table->boolean('return_required')->default(false)->after('funding_source');
            }
            if (!Schema::hasColumn('client_personal_assets', 'return_by')) {
                $table->date('return_by')->nullable()->after('return_required');
            }
            if (!Schema::hasColumn('client_personal_assets', 'last_serviced_at')) {
                $table->date('last_serviced_at')->nullable()->after('return_by');
            }
            if (!Schema::hasColumn('client_personal_assets', 'next_service_due')) {
                $table->date('next_service_due')->nullable()->after('last_serviced_at');
            }
            if (!Schema::hasColumn('client_personal_assets', 'service_provider')) {
                $table->string('service_provider')->nullable()->after('next_service_due');
            }
            if (!Schema::hasColumn('client_personal_assets', 'warranty_expires_at')) {
                $table->date('warranty_expires_at')->nullable()->after('service_provider');
            }
            if (!Schema::hasColumn('client_personal_assets', 'insurance_reference')) {
                $table->string('insurance_reference')->nullable()->after('warranty_expires_at');
            }
            if (!Schema::hasColumn('client_personal_assets', 'disposed_at')) {
                $table->date('disposed_at')->nullable()->after('insurance_reference');
            }
            if (!Schema::hasColumn('client_personal_assets', 'disposal_reason')) {
                $table->string('disposal_reason')->nullable()->after('disposed_at');
            }
            if (!Schema::hasColumn('client_personal_assets', 'portal_visible')) {
                $table->boolean('portal_visible')->default(false)->after('disposal_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table) {
            $cols = ['status', 'ownership', 'funding_source', 'return_required', 'return_by',
                'last_serviced_at', 'next_service_due', 'service_provider',
                'warranty_expires_at', 'insurance_reference',
                'disposed_at', 'disposal_reason', 'portal_visible'];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('client_personal_assets', $c));
            if ($existing) {
                $table->dropColumn($existing);
            }
        });
    }
};
