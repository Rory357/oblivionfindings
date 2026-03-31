<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medications', 'min_hours_between_doses')) {
                $table->decimal('min_hours_between_doses', 6, 2)
                    ->nullable()
                    ->after('max_per_day');
            }
        });

        if (Schema::hasTable('medication_order_versions')) {
            Schema::table('medication_order_versions', function (Blueprint $table) {
                if (!Schema::hasColumn('medication_order_versions', 'min_hours_between_doses')) {
                    $table->decimal('min_hours_between_doses', 6, 2)
                        ->nullable()
                        ->after('max_per_day');
                }
            });
        }

        if (Schema::hasTable('client_controlled_drug_discrepancies')) {
            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'reported')
                ->update(['status' => 'open']);

            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'investigating')
                ->update(['status' => 'under_review']);

            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'resolved')
                ->update(['status' => 'closed']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_controlled_drug_discrepancies')) {
            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'open')
                ->update(['status' => 'reported']);

            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'under_review')
                ->update(['status' => 'investigating']);

            DB::table('client_controlled_drug_discrepancies')
                ->where('status', 'closed')
                ->update(['status' => 'resolved']);
        }

        if (Schema::hasTable('medication_order_versions')) {
            Schema::table('medication_order_versions', function (Blueprint $table) {
                if (Schema::hasColumn('medication_order_versions', 'min_hours_between_doses')) {
                    $table->dropColumn('min_hours_between_doses');
                }
            });
        }

        Schema::table('client_medications', function (Blueprint $table) {
            if (Schema::hasColumn('client_medications', 'min_hours_between_doses')) {
                $table->dropColumn('min_hours_between_doses');
            }
        });
    }
};
