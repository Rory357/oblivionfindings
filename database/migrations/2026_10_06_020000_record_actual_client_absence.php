<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_leave_requests', function (Blueprint $table): void {
            // Unknown legacy facts stay NULL: planned dates are not presence evidence.
            $table->dateTime('departed_at')->nullable();
            $table->foreignId('departed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdrawal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['client_id', 'departed_at', 'returned_at'], 'client_leave_actual_interval');
        });
        Schema::table('clinical_events', function (Blueprint $table): void {
            $table->dateTime('hospital_admitted_at')->nullable();
            $table->dateTime('hospital_discharged_at')->nullable();
            $table->foreignId('hospital_admission_id')->nullable()->constrained('clinical_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hospital_admission_id');
            $table->dropColumn(['hospital_admitted_at', 'hospital_discharged_at']);
        });
        Schema::table('client_leave_requests', function (Blueprint $table): void {
            $table->dropIndex('client_leave_actual_interval');
            foreach (['departed_by', 'returned_by', 'withdrawn_by'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['departed_at', 'returned_at', 'withdrawn_at', 'withdrawal_reason', 'version']);
        });
    }
};
