<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_support_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('goals')->nullable();
            $table->text('routines')->nullable();
            $table->text('preferences')->nullable();
            $table->text('communication_needs')->nullable();
            $table->text('cultural_needs')->nullable();
            $table->text('risk_notes')->nullable();

            $table->date('reviewed_at')->nullable();
            $table->date('next_review_at')->nullable();

            $table->timestamps();

            $table->unique('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_support_plans');
    }
};
