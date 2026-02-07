<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_committee_id')->constrained()->onDelete('cascade');
            $table->foreignId('board_member_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('member'); // chair, member
            $table->date('appointed_at');
            $table->date('term_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['board_committee_id', 'board_member_id']);
            $table->index(['board_member_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_memberships');
    }
};
