<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_committees', function (Blueprint $table) {
            $table->id();
            $table->string('committee_type'); // audit_risk, people, finance
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('terms_of_reference')->nullable();
            $table->foreignId('chair_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->string('meeting_frequency')->default('quarterly'); // monthly, quarterly
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['committee_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_committees');
    }
};
