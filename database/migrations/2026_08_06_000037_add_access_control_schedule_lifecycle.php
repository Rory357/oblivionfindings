<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->string('provider_reconciliation_status', 32)->default('required')->after('version');
            $table->timestamp('provider_reconciliation_required_at')->nullable()->after('provider_reconciliation_status');
            $table->timestamp('deactivated_at')->nullable()->after('provider_reconciliation_required_at');
            $table->foreignId('deactivated_by_user_id')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by_user_id');
        });

        DB::table('access_control_schedules')
            ->whereNull('provider_reconciliation_required_at')
            ->update(['provider_reconciliation_required_at' => now()]);

        Schema::create('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_schedule_id')->constrained('access_control_schedules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 24);
            $table->json('snapshot');
            $table->string('change_reason', 500);
            $table->unsignedInteger('active_credentials_affected')->default(0);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['access_schedule_id', 'version'], 'access_schedule_revisions_schedule_version_uq');
            $table->index(['access_schedule_id', 'created_at'], 'access_schedule_revisions_schedule_created_idx');
        });

        DB::table('access_control_schedules')
            ->orderBy('id')
            ->chunkById(100, function ($schedules): void {
                $now = now();
                $rows = collect($schedules)->map(fn (object $schedule): array => [
                    'access_schedule_id' => $schedule->id,
                    'version' => 1,
                    'action' => 'imported',
                    'snapshot' => json_encode([
                        'name' => $schedule->name,
                        'timezone' => $schedule->timezone,
                        'days' => json_decode($schedule->days, true, flags: JSON_THROW_ON_ERROR),
                        'starts_at' => $schedule->starts_at,
                        'ends_at' => $schedule->ends_at,
                        'is_active' => (bool) $schedule->is_active,
                        'provider_reconciliation_status' => 'required',
                    ], JSON_THROW_ON_ERROR),
                    'change_reason' => 'Imported when governed schedule lifecycle was enabled.',
                    'active_credentials_affected' => DB::table('access_control_credentials')
                        ->where('access_schedule_id', $schedule->id)
                        ->where('status', 'active')
                        ->count(),
                    'recorded_by_user_id' => $schedule->created_by_user_id,
                    'created_at' => $schedule->created_at ?? $now,
                ])->all();

                DB::table('access_control_schedule_revisions')->insert($rows);
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('access_control_schedule_revisions');

        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deactivated_by_user_id');
            $table->dropColumn([
                'version',
                'provider_reconciliation_status',
                'provider_reconciliation_required_at',
                'deactivated_at',
                'deactivation_reason',
            ]);
        });
    }
};
