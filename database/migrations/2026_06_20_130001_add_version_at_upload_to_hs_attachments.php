<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Version-stamp for the controlled-document library: snapshots the procedure's
     * current_version at the moment a document is attached, so each file in the
     * shared polymorphic hs_attachments store can show "Master SWMS · v3" and the
     * detail modal can flag documents attached against superseded versions.
     *
     * Nullable + generic — harmless to the other HsAttachment consumers (HsConsultation,
     * HsCommitteeMeeting, MedicationError, ControlledDrugLossReport, …) which leave it null.
     */
    public function up(): void
    {
        Schema::table('hs_attachments', function (Blueprint $table) {
            $table->unsignedSmallInteger('version_at_upload')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('hs_attachments', function (Blueprint $table) {
            $table->dropColumn('version_at_upload');
        });
    }
};
