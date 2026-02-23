<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_medical_profiles')) {
            return;
        }

        // Convert existing text disabilities to JSON arrays
        DB::table('client_medical_profiles')
            ->whereNotNull('disabilities')
            ->where('disabilities', '!=', '')
            ->orderBy('id')
            ->each(function ($row) {
                $values = array_values(array_filter(
                    array_map('trim', preg_split('/[\n,;]+/', $row->disabilities))
                ));

                DB::table('client_medical_profiles')
                    ->where('id', $row->id)
                    ->update(['disabilities' => json_encode($values)]);
            });

        // Convert existing text allergies to JSON arrays
        DB::table('client_medical_profiles')
            ->whereNotNull('allergies')
            ->where('allergies', '!=', '')
            ->orderBy('id')
            ->each(function ($row) {
                $values = array_values(array_filter(
                    array_map('trim', preg_split('/[\n,;]+/', $row->allergies))
                ));

                DB::table('client_medical_profiles')
                    ->where('id', $row->id)
                    ->update(['allergies' => json_encode($values)]);
            });

        // Change column types to json
        Schema::table('client_medical_profiles', function (Blueprint $table) {
            $table->json('disabilities')->nullable()->change();
            $table->json('allergies')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_medical_profiles')) {
            return;
        }

        // Convert JSON arrays back to newline-separated text
        DB::table('client_medical_profiles')
            ->whereNotNull('disabilities')
            ->where('disabilities', '!=', '')
            ->orderBy('id')
            ->each(function ($row) {
                $values = json_decode($row->disabilities, true);
                if (is_array($values)) {
                    DB::table('client_medical_profiles')
                        ->where('id', $row->id)
                        ->update(['disabilities' => implode("\n", $values)]);
                }
            });

        DB::table('client_medical_profiles')
            ->whereNotNull('allergies')
            ->where('allergies', '!=', '')
            ->orderBy('id')
            ->each(function ($row) {
                $values = json_decode($row->allergies, true);
                if (is_array($values)) {
                    DB::table('client_medical_profiles')
                        ->where('id', $row->id)
                        ->update(['allergies' => implode("\n", $values)]);
                }
            });

        Schema::table('client_medical_profiles', function (Blueprint $table) {
            $table->text('disabilities')->nullable()->change();
            $table->text('allergies')->nullable()->change();
        });
    }
};
