<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('severity')->default('medium')->index(); // low|medium|high|critical
            $table->text('controls')->nullable();
            $table->date('review_date')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['client_id', 'active'], 'cr_client_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_risks');
    }
};
