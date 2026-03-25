<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_incident_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('client_incidents')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');

            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->boolean('portal_visible')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'portal_visible'], 'cia_incident_portal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_incident_attachments');
    }
};
