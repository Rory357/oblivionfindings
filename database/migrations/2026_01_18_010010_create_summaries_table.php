<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();

            $table->string('scope_type')->index(); // staff|client|site
            $table->unsignedBigInteger('scope_id')->index();

            $table->dateTime('period_start')->index();
            $table->dateTime('period_end')->index();

            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->longText('summary_text');
            $table->json('sources')->nullable(); // timeline_event_ids etc

            $table->dateTime('generated_at')->nullable()->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'period_start', 'period_end'], 'summaries_scope_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
