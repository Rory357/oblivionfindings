<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_downtimes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->text('description');
            $table->uuid('request_uuid')->unique();
            $table->string('request_fingerprint', 64);
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'started_at']);
        });
        Schema::create('medication_downtime_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('downtime_id')->constrained('medication_downtimes')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });
        Schema::create('medication_downtime_doses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('downtime_id')->constrained('medication_downtimes')->restrictOnDelete();
            $table->foreignId('dose_slot_id')->constrained('medication_dose_slots')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained()->restrictOnDelete();
            $table->dateTime('scheduled_for');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['downtime_id', 'dose_slot_id']);
        });
        Schema::create('medication_paper_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('downtime_id')->constrained('medication_downtimes')->restrictOnDelete();
            $table->foreignId('downtime_dose_id')->nullable()->constrained('medication_downtime_doses')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained()->restrictOnDelete();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('given_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('witness_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('given_at');
            $table->dateTime('scheduled_for')->nullable();
            $table->string('outcome', 24);
            $table->string('dose_on_paper', 255)->nullable();
            $table->text('notes')->nullable();
            $table->json('observations');
            $table->json('snapshot');
            $table->uuid('request_uuid')->unique();
            $table->string('request_fingerprint', 64);
            // Global medicine + scheduled-slot / PRN time: protects overlapping downtimes too.
            $table->string('dose_identity', 100)->unique();
            $table->timestamps();
        });
        Schema::create('medication_paper_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_entry_id')->constrained('medication_paper_entries')->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('method', 32);
            $table->dateTime('confirmed_at');
            $table->timestamps();
            $table->unique(['paper_entry_id', 'kind']);
        });
        Schema::create('medication_paper_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_entry_id')->unique()->constrained('medication_paper_entries')->restrictOnDelete();
            $table->foreignId('administration_id')->unique()->constrained('client_medication_administrations')->restrictOnDelete();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('medication_downtime_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('downtime_dose_id')->unique()->constrained('medication_downtime_doses')->restrictOnDelete();
            $table->foreignId('administration_id')->nullable()->constrained('client_medication_administrations')->restrictOnDelete();
            $table->foreignId('paper_entry_id')->nullable()->constrained('medication_paper_entries')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['medication_downtime_resolutions', 'medication_paper_postings', 'medication_paper_confirmations', 'medication_paper_entries', 'medication_downtime_doses', 'medication_downtime_sheets', 'medication_downtimes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
