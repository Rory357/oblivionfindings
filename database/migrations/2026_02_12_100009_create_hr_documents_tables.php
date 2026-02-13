<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Document templates
        Schema::create('hr_document_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('category');
            $table->text('content');
            $table->json('merge_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('version')->default(1);
            $table->boolean('approval_required')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'is_active']);
        });

        // Employee documents
        Schema::create('hr_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('title');
            $table->string('category');
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->boolean('is_restricted')->default(false);
            $table->boolean('generated_from_template')->default(false);
            $table->boolean('sent_to_employee')->default(false);
            $table->datetime('sent_at')->nullable();
            $table->boolean('signed_by_employee')->default(false);
            $table->datetime('signed_at')->nullable();
            $table->string('signed_document_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_profile_id', 'category']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_documents');
        Schema::dropIfExists('hr_document_templates');
    }
};
