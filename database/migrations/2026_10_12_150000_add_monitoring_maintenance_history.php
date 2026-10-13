<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_policy_history_epochs', function (Blueprint $table): void {
            $table->string('owner', 32)->primary();
            $table->dateTime('started_at', 6);
        });
        DB::table('monitoring_policy_history_epochs')->insert(['owner' => 'maintenance', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
        Schema::create('monitoring_maintenance_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('window_id')->constrained('monitoring_maintenance_windows')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('monitoring_maintenance_revisions')->restrictOnDelete();
            $table->string('operation', 16);
            $table->string('change_reason', 500);
            $table->dateTime('effective_from', 6);
            $table->dateTime('recorded_at', 6);
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->unique(['window_id', 'version'], 'monitoring_maintenance_revision_identity');
            $table->index(['site_id', 'effective_from'], 'monitoring_maintenance_revision_scope_time');
        });
        Schema::table('monitor_observations', fn (Blueprint $table) => $table->json('maintenance_policy_evidence')->nullable());
    }

    public function down(): void
    {
        if ((Schema::hasTable('monitoring_maintenance_revisions') && DB::table('monitoring_maintenance_revisions')->exists())
            || (Schema::hasColumn('monitor_observations', 'maintenance_policy_evidence') && DB::table('monitor_observations')->whereNotNull('maintenance_policy_evidence')->exists())) {
            throw new RuntimeException('Retain accepted maintenance revisions and observation attribution. Use a forward repair.');
        }
        Schema::table('monitor_observations', fn (Blueprint $table) => $table->dropColumn('maintenance_policy_evidence'));
        Schema::dropIfExists('monitoring_maintenance_revisions');
        Schema::dropIfExists('monitoring_policy_history_epochs');
    }
};
