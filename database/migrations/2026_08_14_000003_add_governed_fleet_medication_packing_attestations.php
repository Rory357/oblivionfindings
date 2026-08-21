<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_medication_transit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'packed_witnessed_by_user_id')) {
                $table->unsignedBigInteger('packed_witnessed_by_user_id')->nullable()->after('packed_witness_name');
                $table->foreign('packed_witnessed_by_user_id', 'frt_med_logs_packed_witness_fk')
                    ->references('id')->on('users');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'packed_witnessed_at')) {
                $table->timestamp('packed_witnessed_at')->nullable()->after('packed_witnessed_by_user_id');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'packing_witness_method')) {
                $table->string('packing_witness_method', 40)->nullable()->after('packed_witnessed_at');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'packing_attestation_event_id')) {
                $table->unsignedBigInteger('packing_attestation_event_id')->nullable()->after('packing_witness_method');
                $table->index('packing_attestation_event_id', 'frt_med_logs_pack_attestation_idx');
                $table->foreign('packing_attestation_event_id', 'frt_med_logs_pack_attestation_fk')
                    ->references('id')->on('fleet_resident_transport_events')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleet_medication_transit_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('fleet_medication_transit_logs', 'packing_attestation_event_id')) {
                $table->dropForeign('frt_med_logs_pack_attestation_fk');
                $table->dropIndex('frt_med_logs_pack_attestation_idx');
                $table->dropColumn('packing_attestation_event_id');
            }
            if (Schema::hasColumn('fleet_medication_transit_logs', 'packing_witness_method')) {
                $table->dropColumn('packing_witness_method');
            }
            if (Schema::hasColumn('fleet_medication_transit_logs', 'packed_witnessed_at')) {
                $table->dropColumn('packed_witnessed_at');
            }
            if (Schema::hasColumn('fleet_medication_transit_logs', 'packed_witnessed_by_user_id')) {
                $table->dropForeign('frt_med_logs_packed_witness_fk');
                $table->dropColumn('packed_witnessed_by_user_id');
            }
        });
    }
};
