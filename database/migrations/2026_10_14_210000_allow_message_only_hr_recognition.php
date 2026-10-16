<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_kudos', function (Blueprint $table): void {
            $table->string('category')->nullable()->change();
            $table->string('impact', 32)->default('thank_you')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_kudos')->whereNull('category')->exists()) {
            throw new RuntimeException('Cannot roll back optional value storage while message-only recognition exists.');
        }
        Schema::table('hr_kudos', function (Blueprint $table): void {
            $table->string('category')->nullable(false)->change();
            $table->string('impact', 32)->default('good_job')->change();
        });
    }
};
