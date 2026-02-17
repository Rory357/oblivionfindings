<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('sites', 'type')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->enum('type', ['head_office', 'house', 'facility', 'residential'])
                ->default('house')
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sites', 'type')) {
            return;
        }

        DB::table('sites')
            ->where('type', 'residential')
            ->update(['type' => 'house']);

        Schema::table('sites', function (Blueprint $table) {
            $table->enum('type', ['head_office', 'house', 'facility'])
                ->default('house')
                ->nullable(false)
                ->change();
        });
    }
};
