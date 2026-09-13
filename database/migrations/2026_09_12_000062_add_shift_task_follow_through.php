<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table) {
            $table->text('steps')->nullable();
            $table->foreignId('source_handover_id')->nullable()->constrained('shift_handovers')->restrictOnDelete();
            $table->string('source_item_key', 64)->nullable();
            $table->foreignId('source_task_id')->nullable()->constrained('shift_tasks')->restrictOnDelete();
            $table->unique(['shift_id', 'source_handover_id', 'source_item_key'], 'shift_task_handover_item_unique');
            $table->foreignId('help_requested_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('help_reason')->nullable();
            $table->string('help_status', 16)->nullable()->index();
            $table->timestamp('help_requested_at')->nullable();
            $table->timestamp('help_responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table) {
            $table->dropUnique('shift_task_handover_item_unique');
            $table->dropConstrainedForeignId('source_handover_id');
            $table->dropConstrainedForeignId('source_task_id');
            $table->dropConstrainedForeignId('help_requested_to');
            $table->dropColumn(['steps', 'source_item_key', 'help_reason', 'help_status', 'help_requested_at', 'help_responded_at']);
        });
    }
};
