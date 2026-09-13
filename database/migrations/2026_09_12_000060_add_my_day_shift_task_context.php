<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table): void {
            // Null scope preserves the existing shift-derived subject for legacy tasks.
            $table->string('task_scope', 16)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('creation_key')->nullable();
            $table->string('creation_hash', 64)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unique(['shift_id', 'creation_key'], 'shift_tasks_creation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table): void {
            $table->dropUnique('shift_tasks_creation_unique');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['task_scope', 'creation_key', 'creation_hash', 'scheduled_at', 'version']);
        });
    }
};
