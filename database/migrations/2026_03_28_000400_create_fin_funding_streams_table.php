<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_funding_streams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('funder_type', [
                'whaikaha',
                'carer_support',
                'nasc',
                'egl_if',
                'acc',
                'te_whatu_ora',
                'msd',
                'private',
                'other',
            ]);
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->unsignedBigInteger('default_revenue_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_funding_streams');
    }
};
