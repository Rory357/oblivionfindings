<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_work_task_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('it_work_tasks')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('source', 32);
            $table->timestamp('recorded_at');
            $table->timestamp('completed_at')->nullable();
            // Immutable provenance survives account deletion. These scalars
            // identify the original people; they never grant current access.
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->json('task_definition');
            $table->json('prerequisite_completions')->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('it_ticket_approvals')->restrictOnDelete();
            $table->text('completion_note')->nullable();
            $table->json('evidence')->nullable();
            $table->unique(['task_id', 'sequence'], 'it_task_completion_sequence_unique');
            $table->unique(['task_id', 'id'], 'it_task_completion_binding_unique');
        });

        Schema::table('it_work_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_completion_id')->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('it_ticket_approvals')->restrictOnDelete();
            // A current pointer cannot refer to another task's evidence.
            $table->foreign(['id', 'current_completion_id'], 'it_task_current_completion_fk')
                ->references(['task_id', 'id'])->on('it_work_task_completions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('it_work_task_completions')->exists()
            || DB::table('it_work_tasks')->whereNotNull('current_completion_id')->orWhereNotNull('approval_id')->exists()) {
            throw new RuntimeException('Retain task completion evidence and approval bindings. Use a forward repair instead of removing their history.');
        }
        Schema::table('it_work_tasks', function (Blueprint $table): void {
            $table->dropForeign('it_task_current_completion_fk');
            $table->dropConstrainedForeignId('approval_id');
            $table->dropColumn('current_completion_id');
        });
        Schema::dropIfExists('it_work_task_completions');
    }
};
