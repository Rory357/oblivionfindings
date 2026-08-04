<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->string('confirmation_mode', 40)->nullable()->after('risk');
            $table->timestamp('impact_acknowledged_at')->nullable()->after('step_up_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_mode', 'impact_acknowledged_at']);
        });
    }
};
