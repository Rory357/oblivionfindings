<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded proof that a Governance action is done (audit P0-5).
 *
 * Files live on the private disk and are only ever served through the
 * authorised download route — the stored path never reaches a page payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('governance_action_evidence')) {
            return;
        }

        Schema::create('governance_action_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_item_id')->constrained('action_items')->cascadeOnDelete();
            $table->string('disk', 40)->default('private');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['action_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_action_evidence');
    }
};
