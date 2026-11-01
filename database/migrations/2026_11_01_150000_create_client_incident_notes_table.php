<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answer 20 (Stephan, 1 Nov 2026): a submitted incident report is never edited (its core fields are locked). A manager
 * adds a dated note beside the reporter's words instead, and the reporter is told. Append-only: kept as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_incident_notes')) {
            return;
        }

        Schema::create('client_incident_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_incident_id')->constrained('client_incidents', indexName: 'client_incident_note_incident_fk')->restrictOnDelete();
            $table->string('field', 40);
            $table->text('note');
            $table->foreignId('author_id')->nullable()->constrained('users', indexName: 'client_incident_note_author_fk')->nullOnDelete();
            $table->string('author_name');
            $table->timestamp('created_at');
            $table->index(['client_incident_id', 'created_at'], 'client_incident_note_lookup_idx');
        });
    }

    public function down(): void
    {
        // Notes beside a report are part of the record: keep them on an application rollback.
    }
};
