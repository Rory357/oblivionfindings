<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_search_keys', function (Blueprint $table) {
            $table->char('fingerprint', 64)->primary();
            $table->date('day')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_search_keys');
    }
};
