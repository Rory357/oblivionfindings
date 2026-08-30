<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECEIPTS_TABLE = 'monitoring_metric_point_receipts';

    public function up(): void
    {
        $legacyObservationId = $this->captureObservationHighWaterMark();

        Schema::table('monitor_observations', function (Blueprint $table): void {
            $table->string('metric_data_class', 64)->nullable()->after('metrics');
            $table->string('metric_privacy_class', 32)->nullable()->after('metric_data_class');
            $table->timestamp('metrics_projected_at', 6)
                ->nullable()
                ->after('ingested_at');
            $table->index(
                ['metrics_projected_at', 'id'],
                'monitor_observations_metrics_projected_idx',
            );
        });

        $this->sealPreMigrationObservations($legacyObservationId);

        Schema::create(self::RECEIPTS_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64);
            $table->foreignId('series_id');
            $table->timestamp('observed_at', 6);
            $table->timestamps(6);

            $table->unique(
                'idempotency_key',
                'monitoring_metric_point_receipts_key_uq',
            );
            $table->foreign('series_id', 'monitoring_metric_point_receipts_series_fk')
                ->references('id')->on('monitoring_metric_series')->restrictOnDelete();
            $table->index(
                ['series_id', 'observed_at'],
                'monitoring_metric_point_receipts_series_observed_idx',
            );
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitor_observations_bi_metric_projection
            BEFORE INSERT ON monitor_observations
            FOR EACH ROW
            BEGIN
                DECLARE expected_domain VARCHAR(64) DEFAULT NULL;
                DECLARE expected_data_class VARCHAR(64) DEFAULT NULL;
                DECLARE expected_privacy_class VARCHAR(32) DEFAULT NULL;

                IF NEW.metrics_projected_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Monitoring metric projection seal must begin pending.';
                END IF;

                SELECT devices.domain
                    INTO expected_domain
                    FROM monitors
                    INNER JOIN devices
                        ON devices.id = monitors.device_id
                       AND devices.deleted_at IS NULL
                    WHERE monitors.id = NEW.monitor_id
                    LIMIT 1;

                IF expected_domain IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Observation metric classification requires a canonical Device.';
                END IF;

                SET expected_data_class = CASE expected_domain
                    WHEN 'tracking' THEN 'tracking_telemetry'
                    WHEN 'iot_healthcare' THEN 'healthcare_telemetry'
                    WHEN 'security' THEN 'security_telemetry'
                    ELSE 'operational'
                END;
                SET expected_privacy_class = CASE expected_domain
                    WHEN 'tracking' THEN 'sensitive'
                    WHEN 'iot_healthcare' THEN 'sensitive'
                    WHEN 'security' THEN 'restricted'
                    ELSE 'standard'
                END;

                IF NEW.metric_data_class IS NULL AND NEW.metric_privacy_class IS NULL THEN
                    SET NEW.metric_data_class = expected_data_class;
                    SET NEW.metric_privacy_class = expected_privacy_class;
                ELSEIF NOT (
                    NEW.metric_data_class <=> expected_data_class
                    AND NEW.metric_privacy_class <=> expected_privacy_class
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Observation metric classification does not match its canonical Device.';
                END IF;
            END
        SQL);

        // Close the narrow DDL window for old-worker inserts that landed after
        // the high-water capture but before the insert trigger became active.
        DB::statement(<<<'SQL'
            UPDATE monitor_observations AS observations
            INNER JOIN monitors ON monitors.id = observations.monitor_id
            INNER JOIN devices
                ON devices.id = monitors.device_id
               AND devices.deleted_at IS NULL
            SET
                observations.metric_data_class = CASE devices.domain
                    WHEN 'tracking' THEN 'tracking_telemetry'
                    WHEN 'iot_healthcare' THEN 'healthcare_telemetry'
                    WHEN 'security' THEN 'security_telemetry'
                    ELSE 'operational'
                END,
                observations.metric_privacy_class = CASE devices.domain
                    WHEN 'tracking' THEN 'sensitive'
                    WHEN 'iot_healthcare' THEN 'sensitive'
                    WHEN 'security' THEN 'restricted'
                    ELSE 'standard'
                END
            WHERE observations.metrics_projected_at IS NULL
              AND observations.metric_data_class IS NULL
              AND observations.metric_privacy_class IS NULL
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitor_observations_bu_metric_projection
            BEFORE UPDATE ON monitor_observations
            FOR EACH ROW
            BEGIN
                DECLARE expected_device_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE expected_collector_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE expected_collector_site_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE expected_device_status VARCHAR(32) DEFAULT NULL;
                DECLARE provenance_source_count INT UNSIGNED DEFAULT 0;
                DECLARE resolved_source_count INT UNSIGNED DEFAULT 0;
                DECLARE canonical_site_count INT UNSIGNED DEFAULT 0;
                DECLARE canonical_site_id BIGINT UNSIGNED DEFAULT NULL;
                DECLARE custody_mismatch_count INT UNSIGNED DEFAULT 0;
                DECLARE canonical_site_available_count INT UNSIGNED DEFAULT 0;

                IF NOT (
                    NEW.id <=> OLD.id
                    AND NEW.tenant_id <=> OLD.tenant_id
                    AND NEW.monitor_id <=> OLD.monitor_id
                    AND NEW.source_key <=> OLD.source_key
                    AND NEW.state <=> OLD.state
                    AND NEW.value <=> OLD.value
                    AND NEW.unit <=> OLD.unit
                    AND NEW.latency_ms <=> OLD.latency_ms
                    AND NEW.message <=> OLD.message
                    AND NEW.metrics <=> OLD.metrics
                    AND NEW.metric_data_class <=> OLD.metric_data_class
                    AND NEW.metric_privacy_class <=> OLD.metric_privacy_class
                    AND NEW.observed_at <=> OLD.observed_at
                    AND NEW.ingested_at <=> OLD.ingested_at
                    AND NEW.created_at <=> OLD.created_at
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Monitoring observation evidence is immutable.';
                END IF;

                IF NOT (
                    NEW.device_id <=> OLD.device_id
                    AND NEW.site_id <=> OLD.site_id
                    AND NEW.collector_id <=> OLD.collector_id
                ) THEN
                    IF NOT (
                        OLD.device_id IS NULL
                        AND OLD.site_id IS NULL
                        AND OLD.collector_id IS NULL
                        AND NEW.device_id IS NOT NULL
                        AND NEW.site_id IS NOT NULL
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Monitoring observation provenance is immutable.';
                    END IF;

                    SELECT
                        monitors.device_id,
                        monitors.collector_id,
                        collectors.site_id,
                        devices.status
                    INTO
                        expected_device_id,
                        expected_collector_id,
                        expected_collector_site_id,
                        expected_device_status
                    FROM monitors
                    INNER JOIN devices
                        ON devices.id = monitors.device_id
                       AND devices.deleted_at IS NULL
                    LEFT JOIN monitoring_collectors AS collectors
                        ON collectors.id = monitors.collector_id
                    WHERE monitors.id = OLD.monitor_id
                    LIMIT 1;

                    SELECT
                        COUNT(DISTINCT provenance.source_key),
                        COUNT(DISTINCT CASE
                            WHEN provenance.site_id IS NOT NULL THEN provenance.source_key
                        END),
                        COUNT(DISTINCT provenance.site_id),
                        MIN(provenance.site_id)
                    INTO
                        provenance_source_count,
                        resolved_source_count,
                        canonical_site_count,
                        canonical_site_id
                    FROM (
                        SELECT
                            CONCAT('assignment:', assignments.id) AS source_key,
                            CAST(NULL AS UNSIGNED) AS site_id
                        FROM device_assignments AS assignments
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), assignments.assignable_id
                        FROM device_assignments AS assignments
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.assignable_type = 'site'
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), site_rooms.site_id
                        FROM device_assignments AS assignments
                        INNER JOIN site_rooms
                            ON assignments.assignable_type = 'room'
                           AND site_rooms.id = assignments.assignable_id
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), clients.site_id
                        FROM device_assignments AS assignments
                        INNER JOIN clients
                            ON assignments.assignable_type = 'client'
                           AND clients.id = assignments.assignable_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), profiles.primary_site_id
                        FROM device_assignments AS assignments
                        INNER JOIN hr_employee_profiles AS profiles
                            ON assignments.assignable_type = 'staff'
                           AND profiles.user_id = assignments.assignable_id
                           AND profiles.deleted_at IS NULL
                           AND profiles.is_active = 1
                           AND (profiles.start_date IS NULL OR profiles.start_date <= CURRENT_DATE())
                           AND (profiles.end_date IS NULL OR profiles.end_date >= CURRENT_DATE())
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), assets.site_id
                        FROM device_assignments AS assignments
                        INNER JOIN assets
                            ON assignments.assignable_type = 'vehicle'
                           AND assets.id = assignments.assignable_id
                           AND assets.status = 'active'
                        LEFT JOIN asset_categories
                            ON asset_categories.id = assets.asset_category_id
                        LEFT JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)
                          AND (LOWER(assets.category) = 'vehicle'
                               OR LOWER(asset_categories.slug) = 'vehicle')
                          AND (assets.client_id IS NULL OR clients.id IS NOT NULL)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), assets.home_site_id
                        FROM device_assignments AS assignments
                        INNER JOIN assets
                            ON assignments.assignable_type = 'vehicle'
                           AND assets.id = assignments.assignable_id
                           AND assets.status = 'active'
                        LEFT JOIN asset_categories
                            ON asset_categories.id = assets.asset_category_id
                        LEFT JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)
                          AND (LOWER(assets.category) = 'vehicle'
                               OR LOWER(asset_categories.slug) = 'vehicle')
                          AND (assets.client_id IS NULL OR clients.id IS NOT NULL)

                        UNION ALL

                        SELECT CONCAT('assignment:', assignments.id), clients.site_id
                        FROM device_assignments AS assignments
                        INNER JOIN assets
                            ON assignments.assignable_type = 'vehicle'
                           AND assets.id = assignments.assignable_id
                           AND assets.status = 'active'
                        LEFT JOIN asset_categories
                            ON asset_categories.id = assets.asset_category_id
                        INNER JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE assignments.device_id = expected_device_id
                          AND assignments.released_at IS NULL
                          AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)
                          AND (LOWER(assets.category) = 'vehicle'
                               OR LOWER(asset_categories.slug) = 'vehicle')

                        UNION ALL

                        SELECT
                            CONCAT('asset-link:', links.id),
                            CAST(NULL AS UNSIGNED)
                        FROM device_asset_links AS links
                        WHERE links.device_id = expected_device_id
                          AND links.unlinked_at IS NULL

                        UNION ALL

                        SELECT CONCAT('asset-link:', links.id), assets.site_id
                        FROM device_asset_links AS links
                        INNER JOIN assets
                            ON assets.id = links.asset_id
                           AND assets.status = 'active'
                        LEFT JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE links.device_id = expected_device_id
                          AND links.unlinked_at IS NULL
                          AND (assets.client_id IS NULL OR clients.id IS NOT NULL)

                        UNION ALL

                        SELECT CONCAT('asset-link:', links.id), assets.home_site_id
                        FROM device_asset_links AS links
                        INNER JOIN assets
                            ON assets.id = links.asset_id
                           AND assets.status = 'active'
                        LEFT JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE links.device_id = expected_device_id
                          AND links.unlinked_at IS NULL
                          AND (assets.client_id IS NULL OR clients.id IS NOT NULL)

                        UNION ALL

                        SELECT CONCAT('asset-link:', links.id), clients.site_id
                        FROM device_asset_links AS links
                        INNER JOIN assets
                            ON assets.id = links.asset_id
                           AND assets.status = 'active'
                        INNER JOIN clients
                            ON clients.id = assets.client_id
                           AND clients.deleted_at IS NULL
                           AND clients.status = 'active'
                        WHERE links.device_id = expected_device_id
                          AND links.unlinked_at IS NULL
                    ) AS provenance;

                    SELECT COUNT(*)
                    INTO custody_mismatch_count
                    FROM device_assignments AS assignments
                    WHERE assignments.device_id = expected_device_id
                      AND assignments.released_at IS NULL
                      AND assignments.assigned_at <= CURRENT_TIMESTAMP(6)
                      AND NOT (assignments.custody_site_id <=> canonical_site_id);

                    SELECT COUNT(*)
                    INTO canonical_site_available_count
                    FROM sites
                    WHERE sites.id = canonical_site_id
                      AND sites.deleted_at IS NULL
                      AND sites.is_active = 1
                      AND (sites.archived IS NULL OR sites.archived = 0);

                    IF expected_device_id IS NULL
                        OR expected_device_status NOT IN ('active', 'degraded', 'offline')
                        OR NOT (NEW.device_id <=> expected_device_id)
                        OR NOT (NEW.collector_id <=> expected_collector_id)
                        OR (expected_collector_id IS NOT NULL
                            AND NOT (NEW.site_id <=> expected_collector_site_id))
                        OR provenance_source_count = 0
                        OR resolved_source_count <> provenance_source_count
                        OR canonical_site_count <> 1
                        OR NOT (NEW.site_id <=> canonical_site_id)
                        OR custody_mismatch_count <> 0
                        OR canonical_site_available_count <> 1
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Monitoring observation provenance must match its canonical scope.';
                    END IF;
                END IF;

                IF NOT (NEW.metrics_projected_at <=> OLD.metrics_projected_at)
                    AND NOT (
                        OLD.metrics_projected_at IS NULL
                        AND NEW.metrics_projected_at IS NOT NULL
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Monitoring metric projection seal is monotonic.';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_metric_point_receipts_bu
            BEFORE UPDATE ON monitoring_metric_point_receipts
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Metric point receipts are immutable.'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_metric_point_receipts_bd
            BEFORE DELETE ON monitoring_metric_point_receipts
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Metric point receipts are immutable.'
        SQL);
    }

    public function captureObservationHighWaterMark(): ?int
    {
        $id = DB::table('monitor_observations')->max('id');

        return $id === null ? null : (int) $id;
    }

    public function sealPreMigrationObservations(?int $highWaterId): void
    {
        if ($highWaterId === null) {
            return;
        }

        DB::table('monitor_observations')
            ->where('id', '<=', $highWaterId)
            ->whereNull('metrics_projected_at')
            ->update(['metrics_projected_at' => DB::raw('CURRENT_TIMESTAMP(6)')]);
    }

    public function down(): void
    {
        if (Schema::hasTable(self::RECEIPTS_TABLE)
            && DB::table(self::RECEIPTS_TABLE)->exists()) {
            throw new RuntimeException('Cannot remove durable metric point replay receipts.');
        }

        if (Schema::hasColumn('monitor_observations', 'metrics_projected_at')
            && DB::table('monitor_observations')->whereNull('metrics_projected_at')->exists()) {
            throw new RuntimeException('Cannot remove an incomplete metric projection seal.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS monitoring_metric_point_receipts_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS monitoring_metric_point_receipts_bd');
        DB::unprepared('DROP TRIGGER IF EXISTS monitor_observations_bu_metric_projection');
        DB::unprepared('DROP TRIGGER IF EXISTS monitor_observations_bi_metric_projection');
        Schema::dropIfExists(self::RECEIPTS_TABLE);

        Schema::table('monitor_observations', function (Blueprint $table): void {
            $table->dropIndex('monitor_observations_metrics_projected_idx');
            $table->dropColumn([
                'metric_data_class',
                'metric_privacy_class',
                'metrics_projected_at',
            ]);
        });
    }
};
