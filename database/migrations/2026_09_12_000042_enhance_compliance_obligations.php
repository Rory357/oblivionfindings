<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_obligations', function (Blueprint $table) {
            if (! Schema::hasColumn('compliance_obligations', 'version_number')) {
                $table->unsignedInteger('version_number')->default(1)->after('status');
            }
            if (! Schema::hasColumn('compliance_obligations', 'completion_notes')) {
                $table->text('completion_notes')->nullable()->after('completed_by');
            }
            if (! Schema::hasColumn('compliance_obligations', 'parent_obligation_id')) {
                $table->foreignId('parent_obligation_id')->nullable()->after('id')->constrained('compliance_obligations')->nullOnDelete();
            }
            if (! Schema::hasColumn('compliance_obligations', 'recurrence_cycle_key')) {
                $table->string('recurrence_cycle_key', 120)->nullable()->after('parent_obligation_id');
                $table->index(['framework', 'obligation_code', 'recurrence_cycle_key'], 'idx_comp_rec_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('compliance_obligations', function (Blueprint $table) {
            if (Schema::hasColumn('compliance_obligations', 'recurrence_cycle_key')) {
                $table->dropIndex('idx_comp_rec_key');
                $table->dropColumn('recurrence_cycle_key');
            }
            if (Schema::hasColumn('compliance_obligations', 'parent_obligation_id')) {
                $table->dropConstrainedForeignId('parent_obligation_id');
            }
            if (Schema::hasColumn('compliance_obligations', 'completion_notes')) {
                $table->dropColumn('completion_notes');
            }
            if (Schema::hasColumn('compliance_obligations', 'version_number')) {
                $table->dropColumn('version_number');
            }
        });
    }
};
