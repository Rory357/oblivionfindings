<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safeguarding redesign — Step 7a (W8).
 *
 * Evidence storage for safeguarding concerns — photos/documents with a per-file
 * note and a sensitivity flag (sensitive files are download-gated by
 * `safeguarding.viewSensitive`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safeguarding_concern_id')->constrained('safeguarding_concerns')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('safeguarding_concern_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_attachments');
    }
};
