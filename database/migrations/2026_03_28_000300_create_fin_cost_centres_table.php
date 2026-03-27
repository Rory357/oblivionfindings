<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_cost_centres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('type', ['site', 'department', 'program', 'project']);
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cost_centres');
    }
};
