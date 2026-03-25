<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('feedback_type', 30);
            $table->string('submitted_by_name', 255)->nullable();
            $table->string('submitted_by_relationship', 100)->nullable();
            $table->text('content');
            $table->integer('rating')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('status', 20)->default('new');
            $table->text('response')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->datetime('responded_at')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'feedback_type']);
            $table->index('status');

            $table->foreign('responded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_feedback');
    }
};
