<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('client_leave_requests')) {
            Schema::create('client_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('destination')->nullable();
                $table->text('support_required')->nullable();
                $table->text('risks_and_mitigations')->nullable();
                $table->text('emergency_contact')->nullable();
                $table->string('status')->default('requested')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['client_id', 'status']);
                $table->index(['client_id', 'starts_on']);
            });
        }

        if (! Schema::hasTable('client_excursion_requests')) {
            Schema::create('client_excursion_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at')->nullable();
                $table->string('destination')->nullable();
                $table->text('activity_description')->nullable();
                $table->string('transport_method')->nullable();
                $table->json('staff_assignments')->nullable();
                $table->text('risk_assessment')->nullable();
                $table->text('outcome_notes')->nullable();
                $table->string('status')->default('proposed')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['client_id', 'status']);
                $table->index(['client_id', 'starts_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_excursion_requests');
        Schema::dropIfExists('client_leave_requests');
    }
};
