<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPE redesign — premium document upload. Evidence attached to PPE records:
 *  - ppe_attachments            → inventory item: certificates / declarations of
 *                                  conformity / purchase invoices / disposal evidence.
 *  - ppe_allocation_attachments → allocation: fit-test records (AS/NZS 1715),
 *                                  signed acknowledgement forms, training certificates.
 *  - ppe_inspection_attachments → inspection: photos + reports.
 * Mirrors fleet_incident_attachments / emergency_drill_attachments; per-file note,
 * alt text (a11y) and a `kind` tag. Premium upload via the shared AttachmentUploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ppe_attachments') && Schema::hasTable('ppe_inventory')) {
            Schema::create('ppe_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_inventory_id')->constrained('ppe_inventory')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // certificate|declaration_of_conformity|disposal_evidence|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('ppe_inventory_id', 'ppe_attach_inv_idx');
            });
        }

        if (! Schema::hasTable('ppe_allocation_attachments') && Schema::hasTable('ppe_allocations')) {
            Schema::create('ppe_allocation_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_allocation_id')->constrained('ppe_allocations')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // fit_test|acknowledgement|training|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('ppe_allocation_id', 'ppe_attach_alloc_idx');
            });
        }

        if (! Schema::hasTable('ppe_inspection_attachments') && Schema::hasTable('ppe_inspections')) {
            Schema::create('ppe_inspection_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_inspection_id')->constrained('ppe_inspections')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // inspection_photo|inspection_report|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('ppe_inspection_id', 'ppe_attach_insp_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_inspection_attachments');
        Schema::dropIfExists('ppe_allocation_attachments');
        Schema::dropIfExists('ppe_attachments');
    }
};
