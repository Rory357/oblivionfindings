<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P.4 — evidence on the helpdesk thread. Polymorphic (ticket | comment |
 * kb_article) per the pre-approved schema; the audit found no clean generic
 * attachment infra to reuse (every module rolls its own model over the shared
 * ServesPrivateAttachments streaming concern), so IT gets the same shape.
 * Files live on the PRIVATE disk and are only reachable through the
 * authorised download route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'it_attachments_attachable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_attachments');
    }
};
