<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->string('handover_status', 30)->default('not_required');
            $table->foreignId('owner_user_id')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('acceptance_notes')->nullable();

            $table->index('handover_status', 'hs_events_handover_status_index');
            $table->foreign('owner_user_id', 'hs_events_owner_user_id_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('accepted_by_user_id', 'hs_events_accepted_by_user_id_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hs_events', function (Blueprint $table) {
            $table->dropForeign('hs_events_owner_user_id_foreign');
            $table->dropForeign('hs_events_accepted_by_user_id_foreign');
            $table->dropIndex('hs_events_handover_status_index');
            $table->dropColumn([
                'handover_status',
                'owner_user_id',
                'accepted_by_user_id',
                'accepted_at',
                'acceptance_notes',
            ]);
        });
    }
};
