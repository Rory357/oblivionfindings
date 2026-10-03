<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_witness_pins', function (Blueprint $table): void {
            $table->string('hash_version', 32)->default('pin_v1');
        });
    }

    public function down(): void
    {
        Schema::table('user_witness_pins', function (Blueprint $table): void {
            $table->dropColumn('hash_version');
        });
    }
};
