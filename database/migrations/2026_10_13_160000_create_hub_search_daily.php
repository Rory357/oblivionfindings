<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_search_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('topic', 32);
            $table->unsignedBigInteger('searches')->default(0);
            $table->unsignedBigInteger('unanswered')->default(0);
            $table->timestamps();
            $table->unique(['day', 'site_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_search_daily');
    }
};
