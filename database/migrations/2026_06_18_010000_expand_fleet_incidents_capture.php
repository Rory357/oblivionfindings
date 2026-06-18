<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet & Asset Incidents redesign — Step 1 (plan §3 / §6.1).
 *
 * Expands `fleet_incidents` from a ~40% record to a complete NZ fleet/asset
 * incident: people aboard + injuries, third parties, witnesses, scene/conditions,
 * damage/recovery/VOR, Police+WorkSafe+ACC regulatory, insurance/cost,
 * investigation/assignment, and register/licence snapshots (PREP-LATER — populated
 * from the Fleet & Assets register/driver profile when that module is built out).
 *
 * Region: NZ only — Land Transport Act 1998 s22 (24h injury/fatal-crash Police
 * report / TCR), WorkSafe/HSWA notifiable, ACC. All columns nullable or defaulted
 * (ConvertEmptyStringsToNull-safe). Severity vocab stays minor/moderate/major/critical
 * (mapped at cross-module boundaries — Gap F4 decided "map, don't migrate").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_incidents')) {
            return;
        }

        Schema::table('fleet_incidents', function (Blueprint $table) {
            // -- 3.1 Vehicle/asset identity & compliance (snapshots — PREP-LATER) --
            $this->addIfMissing($table, 'asset_category', fn () => $table->string('asset_category', 30)->nullable());
            $this->addIfMissing($table, 'vehicle_rego_snapshot', fn () => $table->string('vehicle_rego_snapshot', 20)->nullable());
            $this->addIfMissing($table, 'wof_status_snapshot', fn () => $table->string('wof_status_snapshot', 30)->nullable());
            $this->addIfMissing($table, 'wof_expiry_snapshot', fn () => $table->date('wof_expiry_snapshot')->nullable());
            $this->addIfMissing($table, 'cof_status_snapshot', fn () => $table->string('cof_status_snapshot', 30)->nullable());
            $this->addIfMissing($table, 'cof_expiry_snapshot', fn () => $table->date('cof_expiry_snapshot')->nullable());
            $this->addIfMissing($table, 'odometer_at_incident', fn () => $table->unsignedInteger('odometer_at_incident')->nullable());
            $this->addIfMissing($table, 'fuel_type_snapshot', fn () => $table->string('fuel_type_snapshot', 30)->nullable());

            // -- 3.2 Driver / operator (licence — PREP-LATER) --
            $this->addIfMissing($table, 'driver_licence_number', fn () => $table->string('driver_licence_number', 40)->nullable());
            $this->addIfMissing($table, 'driver_licence_class', fn () => $table->string('driver_licence_class', 20)->nullable());
            $this->addIfMissing($table, 'driver_licence_expiry', fn () => $table->date('driver_licence_expiry')->nullable());
            $this->addIfMissing($table, 'driver_years_held', fn () => $table->unsignedSmallInteger('driver_years_held')->nullable());
            $this->addIfMissing($table, 'driver_on_duty', fn () => $table->boolean('driver_on_duty')->nullable());
            $this->addIfMissing($table, 'supervisor_user_id', fn () => $table->unsignedBigInteger('supervisor_user_id')->nullable());

            // -- 3.3 People aboard (residents/passengers) --
            $this->addIfMissing($table, 'people_aboard', fn () => $table->json('people_aboard')->nullable());
            $this->addIfMissing($table, 'people_aboard_count', fn () => $table->unsignedSmallInteger('people_aboard_count')->nullable());
            $this->addIfMissing($table, 'whanau_informed', fn () => $table->boolean('whanau_informed')->default(false));

            // -- 3.4 Third party --
            $this->addIfMissing($table, 'third_party_involved', fn () => $table->boolean('third_party_involved')->default(false));
            $this->addIfMissing($table, 'third_parties', fn () => $table->json('third_parties')->nullable());

            // -- 3.5 Witnesses --
            $this->addIfMissing($table, 'witnesses', fn () => $table->json('witnesses')->nullable());
            $this->addIfMissing($table, 'attending_officer', fn () => $table->string('attending_officer', 120)->nullable());

            // -- 3.6 Scene & conditions --
            $this->addIfMissing($table, 'road_type', fn () => $table->string('road_type', 40)->nullable());
            $this->addIfMissing($table, 'weather', fn () => $table->string('weather', 40)->nullable());
            $this->addIfMissing($table, 'lighting', fn () => $table->string('lighting', 40)->nullable());
            $this->addIfMissing($table, 'traffic_conditions', fn () => $table->string('traffic_conditions', 40)->nullable());
            $this->addIfMissing($table, 'speed_limit', fn () => $table->unsignedSmallInteger('speed_limit')->nullable());
            $this->addIfMissing($table, 'estimated_speed', fn () => $table->unsignedSmallInteger('estimated_speed')->nullable());
            $this->addIfMissing($table, 'manoeuvre', fn () => $table->string('manoeuvre', 60)->nullable());
            $this->addIfMissing($table, 'road_hazard', fn () => $table->string('road_hazard', 120)->nullable());

            // -- 3.7 Damage, drivability & recovery (VOR) --
            $this->addIfMissing($table, 'damage_classification', fn () => $table->string('damage_classification', 30)->nullable()); // light|repairable|write_off
            $this->addIfMissing($table, 'is_drivable', fn () => $table->boolean('is_drivable')->nullable());
            $this->addIfMissing($table, 'tow_required', fn () => $table->boolean('tow_required')->default(false));
            $this->addIfMissing($table, 'tow_provider', fn () => $table->string('tow_provider', 120)->nullable());
            $this->addIfMissing($table, 'cargo_equipment_damage', fn () => $table->text('cargo_equipment_damage')->nullable());
            $this->addIfMissing($table, 'vehicle_off_road', fn () => $table->boolean('vehicle_off_road')->default(false));
            $this->addIfMissing($table, 'off_road_from', fn () => $table->date('off_road_from')->nullable());
            $this->addIfMissing($table, 'off_road_to', fn () => $table->date('off_road_to')->nullable());
            $this->addIfMissing($table, 'service_resumed_at', fn () => $table->date('service_resumed_at')->nullable());

            // -- 3.8 Police & regulatory (NZ — Land Transport Act s22 / WorkSafe / ACC) --
            $this->addIfMissing($table, 'injury_involved', fn () => $table->boolean('injury_involved')->default(false));
            $this->addIfMissing($table, 'fatality_involved', fn () => $table->boolean('fatality_involved')->default(false));
            $this->addIfMissing($table, 'injury_severity', fn () => $table->string('injury_severity', 30)->nullable()); // none|first_aid|medical|hospitalisation|death
            $this->addIfMissing($table, 'police_report_due_at', fn () => $table->timestamp('police_report_due_at')->nullable()); // occurred_at + 24h (s22)
            $this->addIfMissing($table, 'police_report_logged_at', fn () => $table->timestamp('police_report_logged_at')->nullable());
            $this->addIfMissing($table, 'traffic_crash_report_reference', fn () => $table->string('traffic_crash_report_reference', 60)->nullable());
            $this->addIfMissing($table, 'is_notifiable', fn () => $table->boolean('is_notifiable')->default(false));
            $this->addIfMissing($table, 'worksafe_notification_status', fn () => $table->string('worksafe_notification_status', 30)->nullable()); // pending|notified|acknowledged
            $this->addIfMissing($table, 'worksafe_notified_at', fn () => $table->timestamp('worksafe_notified_at')->nullable());
            $this->addIfMissing($table, 'worksafe_reference', fn () => $table->string('worksafe_reference', 60)->nullable());
            $this->addIfMissing($table, 'acc_claim_lodged', fn () => $table->boolean('acc_claim_lodged')->default(false));
            $this->addIfMissing($table, 'acc_claim_reference', fn () => $table->string('acc_claim_reference', 60)->nullable());
            $this->addIfMissing($table, 'breath_test_administered', fn () => $table->boolean('breath_test_administered')->default(false));
            $this->addIfMissing($table, 'breath_test_result', fn () => $table->string('breath_test_result', 40)->nullable());
            $this->addIfMissing($table, 'drug_test_administered', fn () => $table->boolean('drug_test_administered')->default(false));
            $this->addIfMissing($table, 'drug_test_result', fn () => $table->string('drug_test_result', 40)->nullable());

            // -- 3.9 Insurance & cost --
            $this->addIfMissing($table, 'insurer_name', fn () => $table->string('insurer_name', 120)->nullable());
            $this->addIfMissing($table, 'insurance_excess', fn () => $table->decimal('insurance_excess', 12, 2)->nullable());
            $this->addIfMissing($table, 'insurance_amount_sought', fn () => $table->decimal('insurance_amount_sought', 12, 2)->nullable());
            $this->addIfMissing($table, 'insurance_amount_approved', fn () => $table->decimal('insurance_amount_approved', 12, 2)->nullable());
            $this->addIfMissing($table, 'insurance_claim_status', fn () => $table->string('insurance_claim_status', 30)->nullable());
            $this->addIfMissing($table, 'repair_contractor', fn () => $table->string('repair_contractor', 120)->nullable());
            $this->addIfMissing($table, 'actual_repair_cost', fn () => $table->decimal('actual_repair_cost', 12, 2)->nullable());
            $this->addIfMissing($table, 'total_incident_cost', fn () => $table->decimal('total_incident_cost', 12, 2)->nullable());

            // -- 3.10 Investigation & follow-up --
            $this->addIfMissing($table, 'assigned_to_user_id', fn () => $table->unsignedBigInteger('assigned_to_user_id')->nullable());
            $this->addIfMissing($table, 'root_cause', fn () => $table->text('root_cause')->nullable());
            $this->addIfMissing($table, 'corrective_actions', fn () => $table->text('corrective_actions')->nullable());
            $this->addIfMissing($table, 'contributing_factors', fn () => $table->json('contributing_factors')->nullable());
            $this->addIfMissing($table, 'investigation_completed_at', fn () => $table->timestamp('investigation_completed_at')->nullable());

            // -- 3.12 Non-vehicle asset specifics --
            $this->addIfMissing($table, 'asset_serial_snapshot', fn () => $table->string('asset_serial_snapshot', 80)->nullable());
            $this->addIfMissing($table, 'asset_condition_before', fn () => $table->string('asset_condition_before', 40)->nullable());
            $this->addIfMissing($table, 'asset_condition_after', fn () => $table->string('asset_condition_after', 40)->nullable());
            $this->addIfMissing($table, 'warranty_status', fn () => $table->string('warranty_status', 40)->nullable());
            $this->addIfMissing($table, 'replacement_cost', fn () => $table->decimal('replacement_cost', 12, 2)->nullable());

            // -- Near-miss specifics (blame-free "what could have happened") --
            $this->addIfMissing($table, 'potential_severity', fn () => $table->string('potential_severity', 30)->nullable());
        });

        // Indexes for the time-critical worklists (Police-due, VOR). Short names (MySQL 64-char limit).
        Schema::table('fleet_incidents', function (Blueprint $table) {
            if (! $this->hasIndex('fleet_incidents', 'fi_police_due_idx')) {
                $table->index(['police_report_due_at', 'police_report_logged_at'], 'fi_police_due_idx');
            }
            if (! $this->hasIndex('fleet_incidents', 'fi_vor_idx')) {
                $table->index(['vehicle_off_road', 'service_resumed_at'], 'fi_vor_idx');
            }
            if (! $this->hasIndex('fleet_incidents', 'fi_assigned_idx')) {
                $table->index('assigned_to_user_id', 'fi_assigned_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fleet_incidents')) {
            return;
        }

        Schema::table('fleet_incidents', function (Blueprint $table) {
            foreach (['fi_police_due_idx', 'fi_vor_idx', 'fi_assigned_idx'] as $idx) {
                if ($this->hasIndex('fleet_incidents', $idx)) {
                    $table->dropIndex($idx);
                }
            }
        });

        $columns = [
            'asset_category', 'vehicle_rego_snapshot', 'wof_status_snapshot', 'wof_expiry_snapshot',
            'cof_status_snapshot', 'cof_expiry_snapshot', 'odometer_at_incident', 'fuel_type_snapshot',
            'driver_licence_number', 'driver_licence_class', 'driver_licence_expiry', 'driver_years_held',
            'driver_on_duty', 'supervisor_user_id',
            'people_aboard', 'people_aboard_count', 'whanau_informed',
            'third_party_involved', 'third_parties',
            'witnesses', 'attending_officer',
            'road_type', 'weather', 'lighting', 'traffic_conditions', 'speed_limit', 'estimated_speed',
            'manoeuvre', 'road_hazard',
            'damage_classification', 'is_drivable', 'tow_required', 'tow_provider', 'cargo_equipment_damage',
            'vehicle_off_road', 'off_road_from', 'off_road_to', 'service_resumed_at',
            'injury_involved', 'fatality_involved', 'injury_severity', 'police_report_due_at',
            'police_report_logged_at', 'traffic_crash_report_reference', 'is_notifiable',
            'worksafe_notification_status', 'worksafe_notified_at', 'worksafe_reference',
            'acc_claim_lodged', 'acc_claim_reference', 'breath_test_administered', 'breath_test_result',
            'drug_test_administered', 'drug_test_result',
            'insurer_name', 'insurance_excess', 'insurance_amount_sought', 'insurance_amount_approved',
            'insurance_claim_status', 'repair_contractor', 'actual_repair_cost', 'total_incident_cost',
            'assigned_to_user_id', 'root_cause', 'corrective_actions', 'contributing_factors',
            'investigation_completed_at',
            'asset_serial_snapshot', 'asset_condition_before', 'asset_condition_after', 'warranty_status',
            'replacement_cost', 'potential_severity',
        ];

        Schema::table('fleet_incidents', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('fleet_incidents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /** Add a column only if it's not already present (idempotent re-runs). */
    private function addIfMissing(Blueprint $table, string $column, callable $define): void
    {
        if (! Schema::hasColumn('fleet_incidents', $column)) {
            $define();
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $doctrine = $connection->getDoctrineSchemaManager();

            return array_key_exists($index, $doctrine->listTableIndexes($table));
        } catch (\Throwable $e) {
            // Doctrine DBAL may be unavailable; fall back to a raw information_schema check.
            try {
                $exists = Schema::getConnection()->selectOne(
                    'SELECT 1 AS hit FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                    [$table, $index],
                );

                return $exists !== null;
            } catch (\Throwable $e2) {
                return false;
            }
        }
    }
};
