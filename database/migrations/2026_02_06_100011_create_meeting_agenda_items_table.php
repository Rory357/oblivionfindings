<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_meeting_id')->constrained()->onDelete('cascade');
            $table->integer('order');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('presenter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('duration_minutes')->default(15);
            
            // Item type: standard, decision, consent, for_info
            $table->string('item_type')->default('standard');
            
            // Supporting documents (JSON array of document IDs)
            $table->json('supporting_doc_ids')->nullable();
            
            // Confidential items (board-only)
            $table->boolean('is_confidential')->default(false);
            
            // Link to resolution if this is a decision item (FK added after resolutions table exists)
            $table->unsignedBigInteger('resolution_id')->nullable();
            
            $table->timestamps();

            $table->index(['governance_meeting_id', 'order']);
            $table->index('item_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_agenda_items');
    }
};
