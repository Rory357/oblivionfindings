<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attachments for HR calendar events, stored on the PRIVATE disk and served
     * through the hardened ServesPrivateAttachments route (mime allowlist + CSP
     * sandbox). Tenant-scoped storage path.
     */
    public function up(): void
    {
        Schema::create('hr_calendar_event_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('private');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('event_id', 'hr_cal_attach_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_event_attachments');
    }
};
