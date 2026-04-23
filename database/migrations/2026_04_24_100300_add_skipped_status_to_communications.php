<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_room_communications')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE control_room_communications MODIFY COLUMN status ENUM('pending','sent','delivered','failed','answered','no_answer','skipped') NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            // Postgres uses check constraints; drop and recreate
            DB::statement('ALTER TABLE control_room_communications DROP CONSTRAINT IF EXISTS control_room_communications_status_check');
            DB::statement("ALTER TABLE control_room_communications ADD CONSTRAINT control_room_communications_status_check CHECK (status IN ('pending','sent','delivered','failed','answered','no_answer','skipped'))");
        }
        // sqlite: CHECK constraint is informational; no change needed for testing
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_room_communications')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("UPDATE control_room_communications SET status = 'failed' WHERE status = 'skipped'");
            DB::statement("ALTER TABLE control_room_communications MODIFY COLUMN status ENUM('pending','sent','delivered','failed','answered','no_answer') NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            DB::statement("UPDATE control_room_communications SET status = 'failed' WHERE status = 'skipped'");
            DB::statement('ALTER TABLE control_room_communications DROP CONSTRAINT IF EXISTS control_room_communications_status_check');
            DB::statement("ALTER TABLE control_room_communications ADD CONSTRAINT control_room_communications_status_check CHECK (status IN ('pending','sent','delivered','failed','answered','no_answer'))");
        }
    }
};
