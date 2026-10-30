<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_paper_entries', function (Blueprint $table): void {
            $table->json('clinical_facts')->nullable();
            $table->json('stock_evidence')->nullable();
        });
        Schema::create('medication_paper_recovery_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_entry_id')->unique()->constrained('medication_paper_entries')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('evidence');
            $table->char('fingerprint', 64);
            $table->timestamps();
        });
        Schema::create('medication_paper_stock_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_entry_id')->constrained('medication_paper_entries')->restrictOnDelete();
            $table->index(['paper_entry_id', 'id']);
            $table->uuid('request_uuid')->unique();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->json('evidence');
            $table->char('fingerprint', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Medication paper recovery is forward-only: immutable actual facts, stock reviews and clinical authority evidence must be retained.');
    }
};
