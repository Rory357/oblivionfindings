<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable email templates recruiters pick from when messaging candidates
 * (single or bulk) from the pipeline — e.g. interview invite, timeline update,
 * rejection with feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_candidate_email_templates')) {
            return;
        }

        Schema::create('hr_candidate_email_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_email_templates');
    }
};
