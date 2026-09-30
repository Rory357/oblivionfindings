<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NF-09: drug-interaction rules feed every resident's safety checks, so each
 * rule records who authored it. Nullable: rules created before this change
 * have no known author, and the rule outlives a deleted user account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_interactions') || Schema::hasColumn('medication_interactions', 'created_by')) {
            return;
        }

        Schema::table('medication_interactions', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_interactions') || ! Schema::hasColumn('medication_interactions', 'created_by')) {
            return;
        }

        Schema::table('medication_interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
