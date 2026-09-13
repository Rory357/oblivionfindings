<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_ticket_approvals', function (Blueprint $table): void {
            // Historical requests retain unknown assignment/timing, without a backfill.
            $table->foreignId('primary_approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cover_approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assignment_recorded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminder_prepared_at')->nullable();
            $table->timestamp('reminder_last_checked_at')->nullable();
            $table->string('decision_authority', 64)->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->index(['status', 'expires_at'], 'it_approval_expiry_due');
            $table->index(['status', 'reminder_prepared_at', 'remind_at'], 'it_approval_reminder_due');
        });
    }

    public function down(): void
    {
        $columns = ['primary_approver_user_id', 'cover_approver_user_id', 'assignment_recorded_at',
            'expires_at', 'remind_at', 'reminder_prepared_at', 'reminder_last_checked_at', 'decision_authority', 'expired_at',
            'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'];
        if (DB::table('it_ticket_approvals')->where(function ($query) use ($columns): void {
            foreach ($columns as $column) {
                $query->orWhereNotNull($column);
            }
        })->exists()) {
            throw new RuntimeException('Approval responsibility and timing evidence must be preserved before removing its schema.');
        }
        Schema::table('it_ticket_approvals', function (Blueprint $table) use ($columns): void {
            $table->dropIndex('it_approval_expiry_due');
            $table->dropIndex('it_approval_reminder_due');
            foreach (['primary_approver_user_id', 'cover_approver_user_id', 'cancelled_by_user_id'] as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropColumn($columns);
        });
    }
};
