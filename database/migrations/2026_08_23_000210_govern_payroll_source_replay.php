<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_run_mutexes', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->timestamps();
        });

        DB::table('hr_payroll_run_mutexes')->insertOrIgnore([
            'key' => 'application',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->char('command_key_sha256', 64)->nullable()->after('tenant_id');
            $table->char('command_payload_sha256', 64)->nullable()->after('command_key_sha256');
            $table->string('source_provenance_status', 40)
                ->default('legacy_unverified')
                ->after('command_payload_sha256');
            $table->foreignId('correction_of_run_id')
                ->nullable()
                ->after('source_provenance_status')
                ->constrained('hr_payroll_runs')
                ->restrictOnDelete();
            $table->timestamp('voided_at')->nullable()->after('net_paid_at');
            $table->foreignId('voided_by')
                ->nullable()
                ->after('voided_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
        });

        // No exact legacy leave/date-slice evidence exists to backfill. Retain
        // those runs but quarantine further release; runs with no paid-leave
        // aggregate are identified separately and remain honest legacy history.
        DB::table('hr_payroll_runs')
            ->select('id', 'period_start', 'period_end', 'created_by', 'notes')
            ->orderBy('id')
            ->chunkById(200, function ($runs): void {
                foreach ($runs as $run) {
                    $hasPaidLeave = DB::table('hr_payroll_run_items')
                        ->where('payroll_run_id', $run->id)
                        ->where(function ($items): void {
                            $items->where('leave_hours', '!=', 0)
                                ->orWhere('leave_pay', '!=', 0);
                        })
                        ->exists();
                    $payload = json_encode([
                        'legacy_run_id' => (int) $run->id,
                        'period_start' => (string) $run->period_start,
                        'period_end' => (string) $run->period_end,
                        'created_by' => $run->created_by === null ? null : (int) $run->created_by,
                        'notes' => $run->notes,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                    DB::table('hr_payroll_runs')
                        ->where('id', $run->id)
                        ->update([
                            'command_key_sha256' => hash('sha256', "legacy-payroll-run:{$run->id}"),
                            'command_payload_sha256' => hash('sha256', $payload),
                            'source_provenance_status' => $hasPaidLeave
                                ? 'legacy_unverified_paid_leave'
                                : 'legacy_no_paid_leave',
                        ]);
                }
            });

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->unique('command_key_sha256', 'hr_payroll_runs_command_key_unique');
            $table->unique('correction_of_run_id', 'hr_payroll_runs_correction_unique');
            $table->index(
                ['source_provenance_status', 'status'],
                'hr_payroll_runs_provenance_status_index',
            );
        });

        Schema::create('hr_payroll_source_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')
                ->constrained('hr_payroll_runs')
                ->restrictOnDelete();
            $table->foreignId('payroll_run_item_id')
                ->constrained('hr_payroll_run_items')
                ->restrictOnDelete();
            $table->string('source_type', 16);
            $table->foreignId('timesheet_id')
                ->nullable()
                ->constrained('timesheets')
                ->restrictOnDelete();
            $table->foreignId('leave_request_id')
                ->nullable()
                ->constrained('hr_leave_requests')
                ->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_profile_id')
                ->constrained('hr_employee_profiles')
                ->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->date('source_date');
            $table->decimal('hours', 12, 4);
            $table->decimal('hourly_rate', 14, 4);
            $table->decimal('amount', 14, 2);
            $table->string('source_identity', 191);
            $table->string('active_source_identity', 191)->nullable();
            $table->char('source_payload_sha256', 64);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['payroll_run_id', 'source_identity'],
                'hr_payroll_source_uses_run_source_unique',
            );
            $table->unique(
                'active_source_identity',
                'hr_payroll_source_uses_active_source_unique',
            );
            $table->index(
                ['leave_request_id', 'source_date'],
                'hr_payroll_source_uses_leave_date_index',
            );
            $table->index(
                ['timesheet_id', 'source_date'],
                'hr_payroll_source_uses_timesheet_date_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_source_uses');

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            // MySQL may use the correction uniqueness index to support this
            // self-referencing foreign key. Remove the dependency before the
            // named index so rollback cannot fail with SQLSTATE 1553.
            $table->dropForeign(['correction_of_run_id']);
            $table->dropForeign(['voided_by']);
            $table->dropIndex('hr_payroll_runs_provenance_status_index');
            $table->dropUnique('hr_payroll_runs_correction_unique');
            $table->dropUnique('hr_payroll_runs_command_key_unique');
            $table->dropColumn([
                'command_key_sha256',
                'command_payload_sha256',
                'source_provenance_status',
                'correction_of_run_id',
                'voided_at',
                'voided_by',
                'void_reason',
            ]);
        });

        Schema::dropIfExists('hr_payroll_run_mutexes');
    }
};
