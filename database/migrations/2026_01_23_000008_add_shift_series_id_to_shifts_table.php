<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('shift_series_id')
                ->nullable()
                ->after('id')
                ->constrained('shift_series')
                ->nullOnDelete();

            $table->index(['shift_series_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['shift_series_id', 'starts_at']);
            $table->dropConstrainedForeignId('shift_series_id');
        });
    }
};
