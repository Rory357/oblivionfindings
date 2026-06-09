<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Edit-tracking for the redesigned Shift Notes workspace: an author may edit
// their own note for a window after creation, and managers may edit anytime.
// Recording who last edited (and when) drives the "Edited" badge + the audit
// line in the note detail popup. See ShiftNoteController@update.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('client_notes', 'edited_at')) {
                $table->dateTime('edited_at')->nullable();
            }
            if (! Schema::hasColumn('client_notes', 'edited_by')) {
                $table->unsignedBigInteger('edited_by')->nullable();
                $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            if (Schema::hasColumn('client_notes', 'edited_by')) {
                $table->dropForeign(['edited_by']);
            }
            foreach (['edited_at', 'edited_by'] as $column) {
                if (Schema::hasColumn('client_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
