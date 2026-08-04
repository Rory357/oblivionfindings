<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_snmp_engine_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->char('sender_address_hash', 64);
            $table->char('engine_id_hash', 64);
            $table->unsignedInteger('engine_boots');
            $table->unsignedInteger('engine_time');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(
                ['site_id', 'sender_address_hash', 'engine_id_hash'],
                'monitoring_snmp_engine_scope_uq',
            );
            $table->index(['received_at'], 'monitoring_snmp_engine_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_snmp_engine_states');
    }
};
