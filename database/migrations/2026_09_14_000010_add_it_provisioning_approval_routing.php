<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue items may name a default approver pair and approval window so a
 * submitted request routes its approval automatically instead of waiting for
 * an agent to choose approvers by hand. The values are part of the immutable
 * published contract (ItCatalogItem::CONTRACT_FIELDS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('it_catalog_items', 'approver_user_id')) {
                $table->foreignId('approver_user_id')->nullable()->after('requires_approval')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('it_catalog_items', 'cover_approver_user_id')) {
                $table->foreignId('cover_approver_user_id')->nullable()->after('approver_user_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('it_catalog_items', 'approval_window_days')) {
                $table->unsignedSmallInteger('approval_window_days')->nullable()->after('cover_approver_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('it_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('it_catalog_items', 'approval_window_days')) {
                $table->dropColumn('approval_window_days');
            }
            if (Schema::hasColumn('it_catalog_items', 'cover_approver_user_id')) {
                $table->dropConstrainedForeignId('cover_approver_user_id');
            }
            if (Schema::hasColumn('it_catalog_items', 'approver_user_id')) {
                $table->dropConstrainedForeignId('approver_user_id');
            }
        });
    }
};
