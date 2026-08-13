<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_resident_transports', function (Blueprint $table): void {
            if (! Schema::hasColumn('fleet_resident_transports', 'journey_uuid')) {
                $table->uuid('journey_uuid')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('fleet_resident_transports', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('status');
            }
        });

        Schema::table('fleet_medication_transit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->after('client_id');
                $table->foreign('site_id', 'frt_med_logs_site_fk')->references('id')->on('sites');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'shift_id')) {
                $table->unsignedBigInteger('shift_id')->nullable()->after('site_id');
                $table->foreign('shift_id', 'frt_med_logs_shift_fk')->references('id')->on('shifts');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'medication_order_version')) {
                $table->unsignedInteger('medication_order_version')->nullable()->after('medication_id');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'witness_required')) {
                $table->boolean('witness_required')->default(false)->after('is_controlled_drug');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'medication_order_version_id')) {
                $table->unsignedBigInteger('medication_order_version_id')
                    ->nullable()
                    ->after('medication_order_version');
                $table->foreign('medication_order_version_id', 'frt_med_logs_order_version_fk')
                    ->references('id')->on('medication_order_versions');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'medication_administration_id')) {
                $table->unsignedBigInteger('medication_administration_id')
                    ->nullable()
                    ->after('administered_by_user_id');
                $table->unique('medication_administration_id', 'frt_med_logs_administration_unique');
                $table->foreign('medication_administration_id', 'frt_med_logs_administration_fk')
                    ->references('id')->on('client_medication_administrations');
            }
            if (! Schema::hasColumn('fleet_medication_transit_logs', 'returned_by_user_id')) {
                $table->unsignedBigInteger('returned_by_user_id')
                    ->nullable()
                    ->after('returned_to_house_at');
                $table->foreign('returned_by_user_id', 'frt_med_logs_returned_by_fk')
                    ->references('id')->on('users');
            }
        });

        if (! Schema::hasTable('fleet_resident_transport_events')) {
            Schema::create('fleet_resident_transport_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('transport_id');
                $table->unsignedBigInteger('medication_transit_log_id')->nullable();
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('shift_id')->nullable();
                $table->unsignedBigInteger('asset_id');
                $table->unsignedBigInteger('medication_id')->nullable();
                $table->unsignedBigInteger('medication_order_version_id')->nullable();
                $table->unsignedBigInteger('medication_administration_id')->nullable();
                $table->string('action', 64);
                $table->unsignedBigInteger('actor_user_id');
                $table->unsignedBigInteger('witness_user_id')->nullable();
                $table->uuid('request_uuid')->unique();
                $table->timestamp('occurred_at');
                $table->char('previous_event_hash', 64)->nullable();
                $table->char('event_hash', 64)->unique();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['transport_id', 'id'], 'fleet_transport_events_transport_id_index');
                $table->index(['site_id', 'action'], 'fleet_transport_events_site_action_index');
                $table->index(['client_id', 'medication_id'], 'fleet_transport_events_client_med_index');

                $table->foreign('transport_id', 'frt_events_transport_fk')->references('id')->on('fleet_resident_transports');
                $table->foreign('medication_transit_log_id', 'frt_events_med_log_fk')->references('id')->on('fleet_medication_transit_logs');
                $table->foreign('client_id', 'frt_events_client_fk')->references('id')->on('clients');
                $table->foreign('site_id', 'frt_events_site_fk')->references('id')->on('sites');
                $table->foreign('shift_id', 'frt_events_shift_fk')->references('id')->on('shifts');
                $table->foreign('asset_id', 'frt_events_asset_fk')->references('id')->on('assets');
                $table->foreign('medication_id', 'frt_events_medication_fk')->references('id')->on('client_medications');
                $table->foreign('medication_order_version_id', 'frt_events_order_version_fk')->references('id')->on('medication_order_versions');
                $table->foreign('medication_administration_id', 'frt_events_administration_fk')->references('id')->on('client_medication_administrations');
                $table->foreign('actor_user_id', 'frt_events_actor_fk')->references('id')->on('users');
                $table->foreign('witness_user_id', 'frt_events_witness_fk')->references('id')->on('users');
            });
        }

        $this->backfillCanonicalJourneyColumns();
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_resident_transport_events');

        Schema::table('fleet_medication_transit_logs', function (Blueprint $table): void {
            $foreignKeys = [
                'medication_administration_id' => 'frt_med_logs_administration_fk',
                'medication_order_version_id' => 'frt_med_logs_order_version_fk',
                'returned_by_user_id' => 'frt_med_logs_returned_by_fk',
                'shift_id' => 'frt_med_logs_shift_fk',
                'site_id' => 'frt_med_logs_site_fk',
            ];
            foreach ($foreignKeys as $column => $foreignKey) {
                if (! Schema::hasColumn('fleet_medication_transit_logs', $column)) {
                    continue;
                }
                $table->dropForeign($foreignKey);
                if ($column === 'medication_administration_id') {
                    $table->dropUnique('frt_med_logs_administration_unique');
                }
                $table->dropColumn($column);
            }

            if (Schema::hasColumn('fleet_medication_transit_logs', 'medication_order_version')) {
                $table->dropColumn('medication_order_version');
            }
            if (Schema::hasColumn('fleet_medication_transit_logs', 'witness_required')) {
                $table->dropColumn('witness_required');
            }
        });

        Schema::table('fleet_resident_transports', function (Blueprint $table): void {
            if (Schema::hasColumn('fleet_resident_transports', 'journey_uuid')) {
                $table->dropUnique(['journey_uuid']);
                $table->dropColumn('journey_uuid');
            }
            if (Schema::hasColumn('fleet_resident_transports', 'version')) {
                $table->dropColumn('version');
            }
        });
    }

    private function backfillCanonicalJourneyColumns(): void
    {
        DB::table('fleet_resident_transports')
            ->whereNull('journey_uuid')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('fleet_resident_transports')
                        ->where('id', $row->id)
                        ->whereNull('journey_uuid')
                        ->update(['journey_uuid' => (string) Str::uuid()]);
                }
            });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE fleet_resident_transports AS transport
            INNER JOIN clients AS resident ON resident.id = transport.resident_id
            SET transport.site_id = resident.site_id
            WHERE transport.site_id IS NULL
              AND resident.site_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE fleet_medication_transit_logs AS transit
            INNER JOIN fleet_resident_transports AS transport ON transport.id = transit.transport_id
            INNER JOIN clients AS resident ON resident.id = transit.client_id
            INNER JOIN client_medications AS medication
                ON medication.id = transit.medication_id
               AND medication.client_id = transit.client_id
            SET transit.site_id = transport.site_id,
                transit.shift_id = transport.shift_id,
                transit.medication_order_version = medication.version,
                transit.witness_required = (medication.witness_required = 1 OR medication.controlled_drug = 1)
            WHERE transit.transport_id IS NOT NULL
              AND transport.resident_id = transit.client_id
              AND transport.site_id = resident.site_id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE fleet_medication_transit_logs AS transit
            INNER JOIN medication_order_versions AS order_version
                ON order_version.client_medication_id = transit.medication_id
               AND order_version.client_id = transit.client_id
               AND order_version.version_number = transit.medication_order_version
            SET transit.medication_order_version_id = order_version.id
            WHERE transit.medication_order_version_id IS NULL
        SQL);
    }
};
