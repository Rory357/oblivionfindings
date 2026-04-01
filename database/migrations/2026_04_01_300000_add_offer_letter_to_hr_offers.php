<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->string('offer_letter_path')->nullable()->after('conditions');
            $table->string('offer_letter_name')->nullable()->after('offer_letter_path');
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn(['offer_letter_path', 'offer_letter_name']);
        });
    }
};
