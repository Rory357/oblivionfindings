<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add tenant_id to site_contacts
        if (Schema::hasTable('site_contacts') && !Schema::hasColumn('site_contacts', 'tenant_id')) {
            Schema::table('site_contacts', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        // Add tenant_id to site_documents
        if (Schema::hasTable('site_documents') && !Schema::hasColumn('site_documents', 'tenant_id')) {
            Schema::table('site_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        // Add tenant_id to site_hazard_actions
        if (Schema::hasTable('site_hazard_actions') && !Schema::hasColumn('site_hazard_actions', 'tenant_id')) {
            Schema::table('site_hazard_actions', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        // Add tenant_id to site_checklist_template_items
        if (Schema::hasTable('site_checklist_template_items') && !Schema::hasColumn('site_checklist_template_items', 'tenant_id')) {
            Schema::table('site_checklist_template_items', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('id');
            });
        }

        // Add reconciled_by to house_ledgers
        if (Schema::hasTable('house_ledgers') && !Schema::hasColumn('house_ledgers', 'reconciled_by')) {
            Schema::table('house_ledgers', function (Blueprint $table) {
                $table->foreignId('reconciled_by')->nullable()->after('last_reconciled_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_contacts') && Schema::hasColumn('site_contacts', 'tenant_id')) {
            Schema::table('site_contacts', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('site_documents') && Schema::hasColumn('site_documents', 'tenant_id')) {
            Schema::table('site_documents', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('site_hazard_actions') && Schema::hasColumn('site_hazard_actions', 'tenant_id')) {
            Schema::table('site_hazard_actions', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('site_checklist_template_items') && Schema::hasColumn('site_checklist_template_items', 'tenant_id')) {
            Schema::table('site_checklist_template_items', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('house_ledgers') && Schema::hasColumn('house_ledgers', 'reconciled_by')) {
            Schema::table('house_ledgers', function (Blueprint $table) {
                $table->dropForeign(['reconciled_by']);
                $table->dropColumn('reconciled_by');
            });
        }
    }
};
