<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_alert_tasks', function (Blueprint $table) {
            $table->foreignId('transferred_to_hs_corrective_action_id')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('transferred_by_user_id')->nullable();

            $table->foreign(
                'transferred_to_hs_corrective_action_id',
                'cr_alert_tasks_hs_corrective_action_fk'
            )
                ->references('id')
                ->on('hs_corrective_actions')
                ->nullOnDelete();
            $table->foreign('transferred_by_user_id', 'cr_alert_tasks_transferred_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alert_tasks', function (Blueprint $table) {
            $table->dropForeign('cr_alert_tasks_hs_corrective_action_fk');
            $table->dropForeign('cr_alert_tasks_transferred_by_fk');
            $table->dropColumn([
                'transferred_to_hs_corrective_action_id',
                'transferred_at',
                'transferred_by_user_id',
            ]);
        });
    }
};
