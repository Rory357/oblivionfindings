<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deployment requires ALL audit writers to be stopped and drained until
        // backfill verification and deployment of the new append service finish.
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('audit_tail_event_id')->nullable();
        });
        DB::statement('UPDATE device_command_requests AS requests
            LEFT JOIN (SELECT device_command_request_id, MAX(id) AS tail_id
                FROM device_command_audit_events GROUP BY device_command_request_id) AS tails
            ON tails.device_command_request_id = requests.id
            SET requests.audit_tail_event_id = tails.tail_id');
        $mismatch = DB::selectOne('SELECT COUNT(*) AS mismatches FROM device_command_requests AS requests
            LEFT JOIN (SELECT device_command_request_id, MAX(id) AS tail_id
                FROM device_command_audit_events GROUP BY device_command_request_id) AS tails
            ON tails.device_command_request_id = requests.id
            WHERE NOT (requests.audit_tail_event_id <=> tails.tail_id)');
        if ((int) $mismatch->mismatches !== 0) {
            throw new RuntimeException('Audit tail backfill verification failed. Keep all audit writers stopped.');
        }
    }

    public function down(): void
    {
        if (DB::table('device_command_audit_events')->exists()) {
            throw new RuntimeException('Audit evidence exists. Retain audit tail metadata and quiesce writers before code rollback.');
        }
        Schema::table('device_command_requests', fn (Blueprint $table) => $table->dropColumn('audit_tail_event_id'));
    }
};
