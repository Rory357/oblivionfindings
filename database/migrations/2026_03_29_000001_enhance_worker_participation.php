<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns to hs_committee_meetings
        Schema::table('hs_committee_meetings', function (Blueprint $table) {
            $table->json('confirmed_attendees')->nullable()->after('attendees');
            $table->string('minutes_document_path')->nullable()->after('minutes');
            $table->string('minutes_document_name')->nullable()->after('minutes_document_path');
        });

        // Add columns to hs_consultations
        Schema::table('hs_consultations', function (Blueprint $table) {
            $table->string('document_path')->nullable()->after('description');
            $table->string('document_name')->nullable()->after('document_path');
            $table->string('outcome_document_path')->nullable()->after('outcome');
            $table->string('outcome_document_name')->nullable()->after('outcome_document_path');
        });

        // New polymorphic attachments table
        Schema::create('hs_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('disk')->default('private');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_attachments');

        Schema::table('hs_consultations', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name', 'outcome_document_path', 'outcome_document_name']);
        });

        Schema::table('hs_committee_meetings', function (Blueprint $table) {
            $table->dropColumn(['confirmed_attendees', 'minutes_document_path', 'minutes_document_name']);
        });
    }
};
