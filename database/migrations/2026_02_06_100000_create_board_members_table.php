<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('board_role'); // chair, secretary, member, observer
            $table->date('term_start');
            $table->date('term_end')->nullable();
            $table->boolean('is_independent')->default(true);
            $table->json('committee_memberships')->nullable(); // ['audit_risk', 'people', 'finance']
            $table->text('biography')->nullable();
            $table->string('expertise_areas')->nullable(); // comma-separated
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['board_role', 'is_active']);
            $table->index(['term_start', 'term_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_members');
    }
};
