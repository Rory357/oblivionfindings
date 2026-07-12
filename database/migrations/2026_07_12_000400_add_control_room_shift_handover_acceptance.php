<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_shifts', function (Blueprint $table) {
            $table->string('handover_status', 30)->default('none');
            $table->json('handover_snapshot')->nullable();
            $table->unsignedInteger('handover_version')->default(1);
            $table->timestamp('handover_prepared_at')->nullable();
            $table->timestamp('handover_accepted_at')->nullable();

            $table->index('handover_status', 'cr_shifts_handover_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('control_room_shifts', function (Blueprint $table) {
            $table->dropIndex('cr_shifts_handover_status_index');
            $table->dropColumn([
                'handover_status',
                'handover_snapshot',
                'handover_version',
                'handover_prepared_at',
                'handover_accepted_at',
            ]);
        });
    }
};
