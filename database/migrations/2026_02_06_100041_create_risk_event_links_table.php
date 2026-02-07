<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_register_entry_id')->constrained()->onDelete('cascade');
            
            // Linked event (polymorphic)
            $table->string('event_type'); // incident, alert, safeguarding, audit, breach, complaint
            $table->unsignedBigInteger('event_id');
            $table->string('event_reference')->nullable(); // External reference number
            
            // Event details at time of link
            $table->string('event_severity');
            $table->datetime('event_occurred_at');
            
            // Link metadata
            $table->text('link_rationale')->nullable();
            $table->foreignId('linked_by')->constrained('users');
            $table->datetime('linked_at');
            
            $table->timestamps();

            $table->index(['risk_register_entry_id', 'event_type']);
            $table->index(['event_type', 'event_id']);
            $table->index('event_occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_event_links');
    }
};
