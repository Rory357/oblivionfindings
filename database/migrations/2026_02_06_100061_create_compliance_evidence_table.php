<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_obligation_id')->constrained()->onDelete('cascade');
            
            // Evidence details
            $table->string('evidence_type'); // document, audit_report, certification, system_export, attestation
            $table->string('title');
            $table->text('description')->nullable();
            
            // File or reference
            $table->string('file_path')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('url')->nullable();
            
            // Validity period
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            
            // Upload
            $table->foreignId('uploaded_by')->constrained('users');
            $table->datetime('uploaded_at');
            
            // Verification
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['compliance_obligation_id', 'verified']);
            $table->index(['valid_until', 'verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_evidence');
    }
};
