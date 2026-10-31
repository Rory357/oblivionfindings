<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_training_review_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_representative_id')->unique()->constrained('hs_representatives')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->date('initial_due_date');
            $table->date('anniversary_date');
            $table->string('creation_origin', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('root_obligation_id')->nullable()->unique()->constrained('compliance_obligations')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_training_review_cases');
    }
};
