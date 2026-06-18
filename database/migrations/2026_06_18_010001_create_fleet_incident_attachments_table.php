<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet & Asset Incidents redesign — Step 1 (plan §3.11 / §6.2, Gap F3).
 *
 * Evidence storage for fleet/asset incidents — scene/damage photos, dashcam clips,
 * the TCR / insurance PDFs. Captured at report time and on the detail. Mirrors
 * `safeguarding_attachments`; carries a per-file note + alt text (a11y) + a kind tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_incident_attachments') || ! Schema::hasTable('fleet_incidents')) {
            return;
        }

        Schema::create('fleet_incident_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_incident_id')->constrained('fleet_incidents')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 30)->nullable(); // photo|document|dashcam|tcr|insurance
            $table->text('notes')->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('fleet_incident_id', 'fia_incident_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_incident_attachments');
    }
};
