<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_meeting_id')->constrained()->onDelete('cascade');
            $table->foreignId('resolution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('board_member_id')->constrained()->onDelete('cascade');
            
            // Declaration type: material, related, prejudicial, other
            $table->string('declaration_type');
            $table->text('declaration_text');
            
            // Action taken
            $table->boolean('withdrew_from_voting')->default(false);
            $table->boolean('withdrew_from_discussion')->default(false);
            $table->boolean('recorded_in_minutes')->default(false);
            
            $table->foreignId('recorded_by')->constrained('users');
            $table->datetime('declared_at');
            $table->timestamps();

            $table->index(['governance_meeting_id', 'board_member_id'], 'conflict_decl_gm_bm_idx');
            $table->index('declaration_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_declarations');
    }
};
