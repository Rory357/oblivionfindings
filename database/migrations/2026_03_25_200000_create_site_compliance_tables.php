<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_certifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('certification_type');
            $table->string('name', 255);
            $table->string('issuing_body', 255)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('current');
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('next_review_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('document_path', 500)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->datetime('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['site_id', 'certification_type']);
            $table->index(['status', 'expiry_date']);
        });

        Schema::create('site_compliance_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('check_type');
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('findings')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->string('risk_rating', 20)->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['site_id', 'check_type']);
            $table->index(['status', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_compliance_checks');
        Schema::dropIfExists('site_certifications');
    }
};
