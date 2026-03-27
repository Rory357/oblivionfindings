<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_recurring_journals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annually']);
            $table->date('next_run_date');
            $table->date('last_run_date')->nullable();
            $table->json('template_lines');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'next_run_date'], 'fin_recur_jnl_org_active_next_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_recurring_journals');
    }
};
