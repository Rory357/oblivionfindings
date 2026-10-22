<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change only future omitted-status inserts; never relabel existing people.
        Schema::table('clients', fn (Blueprint $table) => $table->string('status')->default('onboarding')->change());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->string('status')->default('active')->change());
    }
};
