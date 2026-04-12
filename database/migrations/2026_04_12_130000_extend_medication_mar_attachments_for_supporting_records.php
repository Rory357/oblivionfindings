<?php

use App\Models\ClientMedicationAdministration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_mar_attachments')) {
            return;
        }

        Schema::table('medication_mar_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_mar_attachments', 'attachable_type')) {
                $table->nullableMorphs('attachable');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasColumn('medication_mar_attachments', 'client_medication_administration_id')) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement(
                    'ALTER TABLE medication_mar_attachments MODIFY client_medication_administration_id BIGINT UNSIGNED NULL'
                );
            } elseif ($driver === 'pgsql') {
                DB::statement(
                    'ALTER TABLE medication_mar_attachments ALTER COLUMN client_medication_administration_id DROP NOT NULL'
                );
            } elseif ($driver === 'sqlite') {
                Schema::table('medication_mar_attachments', function (Blueprint $table) {
                    $table->unsignedBigInteger('client_medication_administration_id')
                        ->nullable()
                        ->change();
                });
            }
        }

        if (
            Schema::hasColumn('medication_mar_attachments', 'attachable_type')
            && Schema::hasColumn('medication_mar_attachments', 'attachable_id')
            && Schema::hasColumn('medication_mar_attachments', 'client_medication_administration_id')
        ) {
            DB::table('medication_mar_attachments')
                ->whereNull('attachable_type')
                ->whereNotNull('client_medication_administration_id')
                ->update([
                    'attachable_type' => ClientMedicationAdministration::class,
                    'attachable_id' => DB::raw('client_medication_administration_id'),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_mar_attachments')) {
            return;
        }

        if (
            Schema::hasColumn('medication_mar_attachments', 'client_medication_administration_id')
            && Schema::hasColumn('medication_mar_attachments', 'attachable_type')
        ) {
            DB::table('medication_mar_attachments')
                ->whereNull('client_medication_administration_id')
                ->delete();
        }

        Schema::table('medication_mar_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('medication_mar_attachments', 'attachable_type')) {
                $table->dropMorphs('attachable');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasColumn('medication_mar_attachments', 'client_medication_administration_id')) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement(
                    'ALTER TABLE medication_mar_attachments MODIFY client_medication_administration_id BIGINT UNSIGNED NOT NULL'
                );
            } elseif ($driver === 'pgsql') {
                DB::statement(
                    'ALTER TABLE medication_mar_attachments ALTER COLUMN client_medication_administration_id SET NOT NULL'
                );
            } elseif ($driver === 'sqlite') {
                Schema::table('medication_mar_attachments', function (Blueprint $table) {
                    $table->unsignedBigInteger('client_medication_administration_id')
                        ->nullable(false)
                        ->change();
                });
            }
        }
    }
};
