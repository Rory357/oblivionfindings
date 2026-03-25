<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('service_agreements', 'submitted_for_approval_at')) {
                $table->datetime('submitted_for_approval_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('service_agreements', 'submitted_for_approval_by')) {
                $table->unsignedBigInteger('submitted_for_approval_by')->nullable()->after('submitted_for_approval_at');
                $table->foreign('submitted_for_approval_by')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('service_agreements', 'approved_at')) {
                $table->datetime('approved_at')->nullable()->after('submitted_for_approval_by');
            }
            if (! Schema::hasColumn('service_agreements', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('service_agreements', 'total_hours')) {
                $table->decimal('total_hours', 10, 2)->nullable()->after('daily_rate');
            }
            if (! Schema::hasColumn('service_agreements', 'hours_used')) {
                $table->decimal('hours_used', 10, 2)->default(0)->after('total_hours');
            }
            if (! Schema::hasColumn('service_agreements', 'gst_inclusive')) {
                $table->boolean('gst_inclusive')->default(true)->after('hours_used');
            }
            if (! Schema::hasColumn('service_agreements', 'funding_type')) {
                $table->string('funding_type', 100)->nullable()->after('gst_inclusive');
            }
            if (! Schema::hasColumn('service_agreements', 'service_level')) {
                $table->string('service_level', 50)->nullable()->after('funding_type');
            }
            if (! Schema::hasColumn('service_agreements', 'allocated_hours_per_week')) {
                $table->decimal('allocated_hours_per_week', 8, 2)->nullable()->after('service_level');
            }
            if (! Schema::hasColumn('service_agreements', 'nasc_assessor_name')) {
                $table->string('nasc_assessor_name', 255)->nullable()->after('allocated_hours_per_week');
            }
            if (! Schema::hasColumn('service_agreements', 'nasc_support_package_ref')) {
                $table->string('nasc_support_package_ref', 100)->nullable()->after('nasc_assessor_name');
            }
            if (! Schema::hasColumn('service_agreements', 'support_needs_level')) {
                $table->string('support_needs_level', 20)->nullable()->after('nasc_support_package_ref');
            }
            if (! Schema::hasColumn('service_agreements', 'whaikaha_reference')) {
                $table->string('whaikaha_reference', 100)->nullable()->after('support_needs_level');
            }
            if (! Schema::hasColumn('service_agreements', 'funder_contact_name')) {
                $table->string('funder_contact_name', 255)->nullable()->after('whaikaha_reference');
            }
            if (! Schema::hasColumn('service_agreements', 'funder_contact_email')) {
                $table->string('funder_contact_email', 255)->nullable()->after('funder_contact_name');
            }
            if (! Schema::hasColumn('service_agreements', 'funder_contact_phone')) {
                $table->string('funder_contact_phone', 50)->nullable()->after('funder_contact_email');
            }
            if (! Schema::hasColumn('service_agreements', 'client_signatory')) {
                $table->string('client_signatory', 255)->nullable()->after('funder_contact_phone');
            }
            if (! Schema::hasColumn('service_agreements', 'provider_signatory')) {
                $table->string('provider_signatory', 255)->nullable()->after('client_signatory');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_agreements', function (Blueprint $table) {
            $columns = [
                'submitted_for_approval_at', 'submitted_for_approval_by',
                'approved_at', 'approved_by',
                'total_hours', 'hours_used', 'gst_inclusive',
                'funding_type', 'service_level', 'allocated_hours_per_week',
                'nasc_assessor_name', 'nasc_support_package_ref', 'support_needs_level',
                'whaikaha_reference',
                'funder_contact_name', 'funder_contact_email', 'funder_contact_phone',
                'client_signatory', 'provider_signatory',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('service_agreements', $col)) {
                    if (in_array($col, ['submitted_for_approval_by', 'approved_by'])) {
                        $table->dropForeign([$col]);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
