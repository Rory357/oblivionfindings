<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 50)->default('Pacific/Auckland')->after('work_phone');
            }

            if (! Schema::hasColumn('users', 'date_format')) {
                $table->string('date_format', 20)->default('DD/MM/YYYY')->after('timezone');
            }

            if (! Schema::hasColumn('users', 'time_format')) {
                $table->string('time_format', 2)->default('24')->after('date_format');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];

            foreach (['timezone', 'date_format', 'time_format'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
