<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_path_plans')) {
            return;
        }

        Schema::create('client_path_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // The PATH framework pillars. Stored as nullable text/json so a
            // plan can be partially captured during the planning meeting and
            // filled in over time.
            $table->text('dream')->nullable();
            $table->text('north_star')->nullable();
            $table->json('strengths')->nullable();
            $table->json('action_steps')->nullable();
            $table->json('trusted_people')->nullable();
            $table->json('independence_goals')->nullable();
            $table->text('community')->nullable();
            $table->text('meaningful_outcomes')->nullable();

            $table->date('plan_date')->nullable();
            $table->date('next_review_at')->nullable();
            $table->foreignId('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('client_id', 'client_path_plans_client_unique');
            $table->index('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_path_plans');
    }
};
