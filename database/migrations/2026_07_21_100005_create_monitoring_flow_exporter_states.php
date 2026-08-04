<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_flow_exporter_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->char('exporter_hash', 64);
            $table->string('family', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('last_sequence');
            $table->unsignedBigInteger('last_uptime_ms')->nullable();
            $table->unsignedInteger('last_record_count');
            $table->char('last_datagram_hash', 64);
            $table->timestamp('last_exported_at', 6);
            $table->timestamp('last_seen_at', 6);
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_flow_state_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->unique(
                ['site_id', 'exporter_hash', 'family', 'source_id'],
                'monitoring_flow_state_exporter_uq',
            );
            $table->index(['last_seen_at', 'family'], 'monitoring_flow_state_seen_family_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_flow_exporter_states');
    }
};
