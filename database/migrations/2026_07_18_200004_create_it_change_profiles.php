<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ticket_id')->unique()->constrained('it_tickets')->cascadeOnDelete();
            $table->string('change_type', 32)->default('normal');
            $table->string('risk_level', 32)->default('medium');
            $table->boolean('is_restricted')->default(false);
            $table->text('impact_summary')->nullable();
            $table->longText('implementation_plan')->nullable();
            $table->longText('validation_plan')->nullable();
            $table->longText('backout_plan')->nullable();
            $table->timestamp('maintenance_starts_at')->nullable();
            $table->timestamp('maintenance_ends_at')->nullable();
            $table->longText('actual_outcome')->nullable();
            $table->string('validation_result', 32)->nullable();
            $table->longText('validation_summary')->nullable();
            $table->longText('backout_summary')->nullable();
            $table->longText('pir_summary')->nullable();
            $table->timestamp('implemented_at')->nullable();
            $table->foreignId('implemented_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('backed_out_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'change_type', 'risk_level'], 'it_changes_tenant_type_risk_idx');
            $table->index(['tenant_id', 'maintenance_starts_at'], 'it_changes_tenant_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_changes');
    }
};
