<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            if (! Schema::hasColumn('shift_handovers', 'status')) {
                $table->string('status', 32)->default('submitted')->after('incoming_staff_id');
                $table->index('status');
            }

            if (! Schema::hasColumn('shift_handovers', 'follow_up_items')) {
                $table->json('follow_up_items')->nullable()->after('incidents_to_note');
            }

            if (! Schema::hasColumn('shift_handovers', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('follow_up_items');
            }

            if (! Schema::hasColumn('shift_handovers', 'submitted_by')) {
                $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
                $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        DB::table('shift_handovers')
            ->whereNull('acknowledged_at')
            ->update([
                'status' => 'submitted',
                'submitted_at' => DB::raw('COALESCE(submitted_at, created_at, NOW())'),
                'submitted_by' => DB::raw('COALESCE(submitted_by, outgoing_staff_id)'),
            ]);

        DB::table('shift_handovers')
            ->whereNotNull('acknowledged_at')
            ->update([
                'status' => 'acknowledged',
                'submitted_at' => DB::raw('COALESCE(submitted_at, created_at, acknowledged_at, NOW())'),
                'submitted_by' => DB::raw('COALESCE(submitted_by, outgoing_staff_id)'),
            ]);

        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'handover_waiver_reason')) {
                $table->text('handover_waiver_reason')->nullable()->after('completed_by');
            }

            if (! Schema::hasColumn('shifts', 'handover_waived_at')) {
                $table->timestamp('handover_waived_at')->nullable()->after('handover_waiver_reason');
            }

            if (! Schema::hasColumn('shifts', 'handover_waived_by')) {
                $table->unsignedBigInteger('handover_waived_by')->nullable()->after('handover_waived_at');
                $table->foreign('handover_waived_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'handover_waived_by')) {
                $table->dropForeign(['handover_waived_by']);
                $table->dropColumn('handover_waived_by');
            }

            if (Schema::hasColumn('shifts', 'handover_waived_at')) {
                $table->dropColumn('handover_waived_at');
            }

            if (Schema::hasColumn('shifts', 'handover_waiver_reason')) {
                $table->dropColumn('handover_waiver_reason');
            }
        });

        Schema::table('shift_handovers', function (Blueprint $table) {
            if (Schema::hasColumn('shift_handovers', 'submitted_by')) {
                $table->dropForeign(['submitted_by']);
                $table->dropColumn('submitted_by');
            }

            if (Schema::hasColumn('shift_handovers', 'submitted_at')) {
                $table->dropColumn('submitted_at');
            }

            if (Schema::hasColumn('shift_handovers', 'follow_up_items')) {
                $table->dropColumn('follow_up_items');
            }

            if (Schema::hasColumn('shift_handovers', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
        });
    }
};
