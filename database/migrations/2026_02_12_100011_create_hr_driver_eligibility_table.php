<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_driver_eligibility', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('licence_number')->nullable();
            $table->string('licence_class')->nullable();
            $table->json('licence_endorsements')->nullable();
            $table->date('licence_expires_at')->nullable();
            $table->string('licence_document_path')->nullable();
            $table->boolean('can_drive_clients')->default(false);
            $table->foreignId('can_drive_clients_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('can_drive_clients_approved_at')->nullable();
            $table->date('incident_free_since')->nullable();
            $table->datetime('last_reviewed_at')->nullable();
            $table->datetime('next_review_at')->nullable();
            $table->string('status')->default('review_required');
            $table->text('suspension_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('licence_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_driver_eligibility');
    }
};
