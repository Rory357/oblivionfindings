<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_series', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Recurrence definition
            $table->date('start_date');
            $table->date('end_date');
            $table->string('timezone')->default('Pacific/Auckland');
            $table->json('by_weekday'); // e.g. ["mon","wed","fri"]

            // Times are stored as "HH:MM" in series and expanded to starts_at/ends_at on Shift.
            $table->string('starts_time', 5); // HH:MM
            $table->string('ends_time', 5);   // HH:MM

            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index(['client_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_series');
    }
};
