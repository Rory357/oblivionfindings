<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->json('origin_context')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('device_command_requests')->whereNotNull('origin_context')->exists()) {
            throw new RuntimeException('Signed command origin evidence exists. Retain the column and disable the client action instead.');
        }
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->dropColumn('origin_context');
        });
    }
};
