<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incident_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_incident_id')->constrained('client_incidents')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_incident_id', 'completed_at']);
            $table->index(['assigned_to_user_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_followups');
    }
};
