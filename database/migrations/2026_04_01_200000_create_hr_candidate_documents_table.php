<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('hr_applications')->nullOnDelete();
            $table->string('category'); // cv, cover_letter, qualification, certification, police_vetting, first_aid, driver_licence, reference_letter, portfolio, other
            $table->string('title');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_documents');
    }
};
