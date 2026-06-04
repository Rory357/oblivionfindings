<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_emergency_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            // evacuation, fire, civil_defence, lockdown, business_continuity …
            $table->string('plan_type', 60)->default('evacuation');
            $table->string('title');
            $table->date('last_reviewed_at')->nullable();
            $table->unsignedSmallInteger('review_interval_months')->default(12);
            $table->date('next_review_at')->nullable();
            $table->string('status', 30)->default('active'); // active, draft, archived
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'next_review_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_emergency_plans');
    }
};
