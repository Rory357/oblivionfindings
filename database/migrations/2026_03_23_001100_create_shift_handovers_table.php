<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('outgoing_shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->unsignedBigInteger('incoming_shift_id')->nullable();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('outgoing_staff_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('incoming_staff_id')->nullable();
            $table->text('handover_notes');
            $table->string('client_mood')->nullable();
            $table->json('tasks_pending')->nullable();
            $table->json('medications_due')->nullable();
            $table->json('incidents_to_note')->nullable();
            $table->datetime('acknowledged_at')->nullable();
            $table->timestamps();

            $table->foreign('incoming_shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('incoming_staff_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_handovers');
    }
};
