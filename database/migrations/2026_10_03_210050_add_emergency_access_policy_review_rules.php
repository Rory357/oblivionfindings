<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_glass_policies', function (Blueprint $table): void {
            $table->string('second_person')->default('optional');
            $table->unsignedTinyInteger('review_days')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('break_glass_policies', fn (Blueprint $table) => $table->dropColumn(['second_person', 'review_days']));
    }
};
