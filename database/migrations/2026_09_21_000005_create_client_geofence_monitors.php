<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_geofence_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('client_geofence_rules')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('client_geofence_rule_versions')->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('device_assignments')->restrictOnDelete();
            $table->foreignId('consent_id')->constrained('client_consents')->restrictOnDelete();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('access_fingerprint', 64);
            $table->string('operation_key', 64)->unique();
            $table->timestamp('last_observed_at')->nullable();
            $table->string('schedule_window', 10)->nullable();
            $table->string('position_status', 20)->default('unknown');
            $table->unsignedInteger('breach_count')->default(0);
            $table->index(['rule_id', 'ended_at']);
            $table->index(['assignment_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('client_geofence_monitors')->exists()) {
            throw new RuntimeException('Client monitoring evidence exists; rollback requires an evidence retention plan.');
        }
        Schema::dropIfExists('client_geofence_monitors');
    }
};
