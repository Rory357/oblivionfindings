<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_support_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('assessment_id')->constrained('medication_self_admin_assessments');
            $table->string('agreed_by_role', 24);
            $table->string('agreed_by_name');
            $table->string('method', 16);
            $table->foreignId('witness_id')->nullable()->constrained('users');
            $table->foreignId('recorded_by')->constrained('users');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('ordering_responsibility', 24);
            $table->text('person_responsibilities');
            $table->text('staff_responsibilities');
            $table->text('storage_notes')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('medication_support_agreements');
            $table->timestamps();
            $table->index(['client_id', 'id']);
        });
        Schema::create('medication_support_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_medication_id')->constrained('client_medications');
            $table->foreignId('assessment_id')->constrained('medication_self_admin_assessments');
            $table->string('mode', 24);
            $table->string('previous_mode', 24)->nullable();
            $table->string('reason', 64);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->dateTime('occurred_at');
            $table->dateTime('effective_at');
            $table->timestamps();
            $table->index(['client_medication_id', 'effective_at', 'id'], 'med_support_effective');
        });
        Schema::table('medication_self_admin_assessments', function (Blueprint $table) {
            $table->foreignId('support_agreement_id')->nullable()->constrained('medication_support_agreements');
        });
    }

    public function down(): void
    {
        Schema::table('medication_self_admin_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_agreement_id');
        });
        Schema::dropIfExists('medication_support_changes');
        Schema::dropIfExists('medication_support_agreements');
    }
};
