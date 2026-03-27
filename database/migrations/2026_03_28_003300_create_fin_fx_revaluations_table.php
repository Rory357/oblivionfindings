<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_fx_revaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->date('revaluation_date');
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fin_fiscal_periods')->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->decimal('total_gain_loss', 14, 2)->default(0);
            $table->enum('status', ['draft', 'posted', 'reversed']);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fx_revaluations');
    }
};
