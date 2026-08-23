<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Merge/migration order: after 2026_08_23_000210 (PAY-LEAVE-REPLAY) and
 * 2026_08_23_000220 (RESP-EVIDENCE). This migration has no direct schema
 * dependency on either branch; it extends current-main HR and roster tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offboarding_checklists', function (Blueprint $table): void {
            $table->date('previous_employee_end_date')
                ->nullable()
                ->after('due_date');
        });

        Schema::create('workforce_availability_coverage_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('source_type', 48);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->unsignedBigInteger('replacement_request_id')->nullable();
            $table->foreign(
                'replacement_request_id',
                'workforce_availability_replacement_fk',
            )
                ->references('id')
                ->on('shift_replacement_requests')
                ->nullOnDelete();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->string('action_kind', 32);
            $table->string('status', 24)->default('open');
            $table->dateTime('window_starts_at');
            $table->dateTime('window_ends_at')->nullable();
            $table->boolean('manages_replacement')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'shift_id'],
                'workforce_availability_source_shift_unique',
            );
            $table->index(
                ['owner_user_id', 'status'],
                'workforce_availability_owner_status_idx',
            );
            $table->index(
                ['replacement_request_id', 'status'],
                'workforce_availability_replacement_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_availability_coverage_actions');

        Schema::table('hr_offboarding_checklists', function (Blueprint $table): void {
            $table->dropColumn('previous_employee_end_date');
        });
    }
};
