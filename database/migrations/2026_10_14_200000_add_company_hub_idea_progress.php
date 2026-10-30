<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_ideas', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('hub_idea_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_idea_id')->constrained('hub_ideas')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note');
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->unsignedInteger('source_version');
            $table->boolean('legacy')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['hub_idea_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_idea_updates');
        Schema::table('hub_ideas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
