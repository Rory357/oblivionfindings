<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->string('plan_type');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('content')->nullable();
            $table->integer('version')->default(1);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('care_plans')->nullOnDelete();

            $table->index(['organization_id', 'client_id']);
            $table->index('status');
            $table->index('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
