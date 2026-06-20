<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPE redesign — lifecycle audit attribution. Adds:
 *  - ppe_allocations.acknowledged_by  (who recorded the worker acknowledgement;
 *    the acknowledged/acknowledged_at columns already exist from the original migration).
 *  - ppe_inventory condemn/dispose audit columns (reason + who + when + disposal method)
 *    so "Condemn" / "Dispose" become first-class actions with a real audit trail
 *    instead of a side-effect of an inspection. All additive + nullable (safe/reversible).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ppe_allocations') && ! Schema::hasColumn('ppe_allocations', 'acknowledged_by')) {
            Schema::table('ppe_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');
                $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('ppe_inventory') && ! Schema::hasColumn('ppe_inventory', 'condemned_at')) {
            Schema::table('ppe_inventory', function (Blueprint $table) {
                $table->dateTime('condemned_at')->nullable()->after('next_inspection_due');
                $table->unsignedBigInteger('condemned_by')->nullable()->after('condemned_at');
                $table->text('condemned_reason')->nullable()->after('condemned_by');
                $table->dateTime('disposed_at')->nullable()->after('condemned_reason');
                $table->unsignedBigInteger('disposed_by')->nullable()->after('disposed_at');
                $table->string('disposal_method')->nullable()->after('disposed_by');

                $table->foreign('condemned_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('disposed_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ppe_allocations') && Schema::hasColumn('ppe_allocations', 'acknowledged_by')) {
            Schema::table('ppe_allocations', function (Blueprint $table) {
                $table->dropForeign(['acknowledged_by']);
                $table->dropColumn('acknowledged_by');
            });
        }

        if (Schema::hasTable('ppe_inventory') && Schema::hasColumn('ppe_inventory', 'condemned_at')) {
            Schema::table('ppe_inventory', function (Blueprint $table) {
                $table->dropForeign(['condemned_by']);
                $table->dropForeign(['disposed_by']);
                $table->dropColumn([
                    'condemned_at', 'condemned_by', 'condemned_reason',
                    'disposed_at', 'disposed_by', 'disposal_method',
                ]);
            });
        }
    }
};
