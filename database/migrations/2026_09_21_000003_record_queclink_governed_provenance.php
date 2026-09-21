<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->boolean('was_governed')->default(false);
        });
        // Default sequence/role values also exist on genuine legacy commands.
        // Only actual linkage is evidence that an existing row was governed.
        DB::table('queclink_pending_commands')->where(function ($query): void {
            $query->whereNotNull('device_command_request_id')->orWhereNotNull('device_command_attempt_id');
        })->update(['was_governed' => true]);
    }

    public function down(): void
    {
        if (DB::table('queclink_pending_commands')->where('was_governed', true)->exists()) {
            throw new RuntimeException('Governed provider provenance exists. Retain this evidence and disable delivery instead.');
        }
        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->dropColumn('was_governed');
        });
    }
};
