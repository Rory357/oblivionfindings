<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_errors', function (Blueprint $table) {
            $table->unsignedTinyInteger('confirmed_sac')->nullable();
            $table->unsignedBigInteger('sac_confirmed_by')->nullable();
            $table->dateTime('sac_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Confirmed medication classifications are retained evidence; use a reviewed forward migration.');
    }
};
