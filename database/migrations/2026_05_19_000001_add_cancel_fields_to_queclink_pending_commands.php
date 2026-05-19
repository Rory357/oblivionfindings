<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_pending_commands', function (Blueprint $table) {
            if (! Schema::hasColumn('queclink_pending_commands', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('acked_at');
            }
            if (! Schema::hasColumn('queclink_pending_commands', 'cancelled_by_user_id')) {
                $table->foreignId('cancelled_by_user_id')
                    ->nullable()
                    ->after('cancelled_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE queclink_pending_commands MODIFY status ENUM('queued','sent','acked','failed','expired','cancelled') NOT NULL DEFAULT 'queued'");
        }

        try {
            Schema::table('queclink_pending_commands', function (Blueprint $table) {
                $table->dropUnique('qpc_imei_serial_created_unique');
                $table->index(['imei', 'serial_number', 'created_at'], 'qpc_imei_serial_created_index');
            });
        } catch (\Throwable) {
            // Older local databases may already have this as a non-unique index.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('queclink_pending_commands', function (Blueprint $table) {
                $table->dropIndex('qpc_imei_serial_created_index');
                $table->unique(['imei', 'serial_number', 'created_at'], 'qpc_imei_serial_created_unique');
            });
        } catch (\Throwable) {
            // Keep rollback tolerant across local database variants.
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE queclink_pending_commands MODIFY status ENUM('queued','sent','acked','failed','expired') NOT NULL DEFAULT 'queued'");
        }

        Schema::table('queclink_pending_commands', function (Blueprint $table) {
            if (Schema::hasColumn('queclink_pending_commands', 'cancelled_by_user_id')) {
                $table->dropConstrainedForeignId('cancelled_by_user_id');
            }
            if (Schema::hasColumn('queclink_pending_commands', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
