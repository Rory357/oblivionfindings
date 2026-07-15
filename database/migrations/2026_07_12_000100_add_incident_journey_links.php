<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('client_id');
            $table->foreignId('hs_event_id')->nullable()->after('site_id');

            $table->index('site_id', 'client_incidents_site_id_index');
            $table->unique('hs_event_id', 'client_incidents_hs_event_id_unique');

            $table->foreign('site_id', 'client_incidents_site_id_foreign')
                ->references('id')
                ->on('sites')
                ->nullOnDelete();
            $table->foreign('hs_event_id', 'client_incidents_hs_event_id_foreign')
                ->references('id')
                ->on('hs_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropForeign('client_incidents_site_id_foreign');
            $table->dropForeign('client_incidents_hs_event_id_foreign');
            $table->dropIndex('client_incidents_site_id_index');
            $table->dropUnique('client_incidents_hs_event_id_unique');
            $table->dropColumn(['site_id', 'hs_event_id']);
        });
    }
};
