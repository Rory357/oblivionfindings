<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['shifts', 'shift_series'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'required_licence_class')) {
                    $table->string('required_licence_class', 10)->nullable()->after('coverage_roles');
                }

                if (! Schema::hasColumn($tableName, 'required_licence_endorsements')) {
                    $table->json('required_licence_endorsements')->nullable()->after('required_licence_class');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['shifts', 'shift_series'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = collect(['required_licence_class', 'required_licence_endorsements'])
                    ->filter(fn (string $column) => Schema::hasColumn($tableName, $column))
                    ->all();

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
