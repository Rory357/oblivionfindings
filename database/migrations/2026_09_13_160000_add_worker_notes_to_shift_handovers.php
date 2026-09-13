<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->longText('worker_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shift_handovers', fn (Blueprint $table) => $table->dropColumn('worker_notes'));
    }
};
