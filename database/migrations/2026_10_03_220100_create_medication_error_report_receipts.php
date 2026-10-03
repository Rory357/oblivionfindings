<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One durable identity covers both a report and an added duplicate account.
        Schema::create('medication_error_report_receipts', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('fingerprint', 64);
            $table->foreignId('medication_error_id')->nullable()->constrained('medication_errors')->restrictOnDelete();
            $table->string('kind', 16);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_error_report_receipts');
    }
};
