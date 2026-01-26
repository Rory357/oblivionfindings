<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_escalation_rules', function (Blueprint $table) {
            $table->boolean('must_ack_before_close')->default(false)->after('require_ack');
            $table->json('tiers')->nullable()->after('escalate_to_role_groups');
        });
    }

    public function down(): void
    {
        Schema::table('notification_escalation_rules', function (Blueprint $table) {
            $table->dropColumn(['must_ack_before_close', 'tiers']);
        });
    }
};
