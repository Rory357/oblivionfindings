<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('control_room_playbooks')) {
            return;
        }

        Schema::table('control_room_signal_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('control_room_signal_rules', 'playbook_id')) {
                $table->unsignedBigInteger('playbook_id')->nullable();
            }

            $table->foreign('playbook_id')
                ->references('id')
                ->on('control_room_playbooks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('control_room_signal_rules')) {
            return;
        }

        Schema::table('control_room_signal_rules', function (Blueprint $table) {
            $table->dropForeign(['playbook_id']);
        });
    }
};
