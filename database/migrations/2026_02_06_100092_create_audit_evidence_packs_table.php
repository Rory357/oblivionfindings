<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_evidence_packs', function (Blueprint $table) {
            $table->id();
            
            // Pack identity
            $table->string('pack_name');
            $table->string('audit_type'); // nga_paerewa, charities, funding, iso, internal
            $table->date('audit_date_range_start');
            $table->date('audit_date_range_end');
            
            // Generation
            $table->datetime('generated_at');
            $table->foreignId('generated_by')->constrained('users');
            
            // File
            $table->string('file_path');
            $table->string('file_size');
            $table->string('checksum');
            
            // Contents
            $table->json('contents_manifest'); // List of all included evidence
            $table->json('included_data_types'); // What was included
            
            // Retention
            $table->date('retention_until');
            $table->boolean('deleted_after_retention')->default(false);
            
            // Legal hold
            $table->boolean('legal_hold')->default(false);
            $table->text('legal_hold_reason')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['audit_type', 'generated_at']);
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_evidence_packs');
    }
};
