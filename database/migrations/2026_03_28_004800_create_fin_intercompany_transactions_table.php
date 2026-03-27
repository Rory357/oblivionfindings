<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_intercompany_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('fin_consolidation_groups')->cascadeOnDelete();
            $table->foreignId('from_entity_id')->constrained('fin_consolidation_entities')->cascadeOnDelete();
            $table->foreignId('to_entity_id')->constrained('fin_consolidation_entities')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->foreignId('from_journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->foreignId('to_journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->enum('status', ['pending', 'posted', 'eliminated'])->default('pending');
            $table->unsignedBigInteger('eliminated_in_run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'status'], 'fin_ict_group_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_intercompany_transactions');
    }
};
