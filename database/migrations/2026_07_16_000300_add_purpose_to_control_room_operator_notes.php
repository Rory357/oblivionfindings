<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_operator_notes', function (Blueprint $table) {
            $table->string('purpose', 40)->default('general')->after('type');
            $table->index(
                ['alert_id', 'purpose', 'created_at', 'id'],
                'cr_operator_notes_alert_purpose_created_id_idx',
            );
        });

        DB::table('control_room_operator_notes')
            ->whereIn('type', ['escalation', 'handover'])
            ->update(['purpose' => 'escalation_handover']);
    }

    public function down(): void
    {
        Schema::table('control_room_operator_notes', function (Blueprint $table) {
            $table->dropIndex('cr_operator_notes_alert_purpose_created_id_idx');
            $table->dropColumn('purpose');
        });
    }
};
