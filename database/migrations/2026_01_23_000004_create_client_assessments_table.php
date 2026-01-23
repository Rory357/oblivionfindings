<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type')->nullable();
            $table->string('score')->nullable();
            $table->text('notes')->nullable();

            $table->date('assessed_at')->nullable();
            $table->date('next_review_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_assessments');
    }
};
