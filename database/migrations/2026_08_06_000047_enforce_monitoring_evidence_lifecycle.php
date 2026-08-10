<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOT_UPDATE_GUARD = 'monitoring_snapshots_before_update_guard';

    private const SNAPSHOT_UPDATE_AUDIT = 'monitoring_snapshots_after_update_audit';

    private const SNAPSHOT_DELETE_GUARD = 'monitoring_snapshots_before_delete_guard';

    private const SNAPSHOT_EVENT_UPDATE_GUARD = 'monitoring_snapshot_events_update_guard';

    private const SNAPSHOT_EVENT_DELETE_GUARD = 'monitoring_snapshot_events_delete_guard';

    private const TOMBSTONE_INSERT_GUARD = 'monitoring_tombstones_before_insert_guard';

    private const TOMBSTONE_UPDATE_GUARD = 'monitoring_tombstones_before_update_guard';

    private const TOMBSTONE_DELETE_GUARD = 'monitoring_tombstones_before_delete_guard';

    private const SERIES_UPDATE_GUARD = 'monitoring_series_before_update_guard';

    private const SERIES_UPDATE_AUDIT = 'monitoring_series_after_update_audit';

    private const SERIES_DELETE_GUARD = 'monitoring_series_before_delete_guard';

    private const SERIES_EVENT_UPDATE_GUARD = 'monitoring_series_events_update_guard';

    private const SERIES_EVENT_DELETE_GUARD = 'monitoring_series_events_delete_guard';

    public function up(): void
    {
        $this->assertExistingEvidenceIntegrity();

        Schema::create('monitoring_configuration_snapshot_storage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id');
            $table->string('from_storage_state', 16);
            $table->string('to_storage_state', 16);
            $table->timestamp('from_payload_deleted_at', 6)->nullable();
            $table->timestamp('to_payload_deleted_at', 6)->nullable();
            $table->string('transition_kind', 32);
            $table->timestamp('occurred_at', 6);

            $table->foreign('snapshot_id', 'monitoring_snapshot_storage_event_snapshot_fk')
                ->references('id')
                ->on('monitoring_configuration_snapshots')
                ->restrictOnDelete();
            $table->index(
                ['snapshot_id', 'occurred_at'],
                'monitoring_snapshot_storage_event_time_idx',
            );
        });

        Schema::create('monitoring_metric_series_pointer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id');
            $table->timestamp('from_first_point_at', 6)->nullable();
            $table->timestamp('to_first_point_at', 6)->nullable();
            $table->timestamp('from_last_point_at', 6)->nullable();
            $table->timestamp('to_last_point_at', 6)->nullable();
            $table->string('transition_kind', 32);
            $table->timestamp('occurred_at', 6);

            $table->foreign('series_id', 'monitoring_series_pointer_event_series_fk')
                ->references('id')
                ->on('monitoring_metric_series')
                ->restrictOnDelete();
            $table->index(
                ['series_id', 'occurred_at'],
                'monitoring_series_pointer_event_time_idx',
            );
        });

        $this->installSnapshotGuards();
        $this->installTombstoneGuards();
        $this->installSeriesGuards();
        $this->installTransitionEventGuards();
    }

    public function down(): void
    {
        if ((Schema::hasTable('monitoring_configuration_snapshot_storage_events')
                && DB::table('monitoring_configuration_snapshot_storage_events')->exists())
            || (Schema::hasTable('monitoring_metric_series_pointer_events')
                && DB::table('monitoring_metric_series_pointer_events')->exists())) {
            throw new RuntimeException(
                'Cannot remove monitoring evidence lifecycle enforcement while retained storage or pointer transition evidence exists.',
            );
        }

        $this->dropGuards();
        Schema::dropIfExists('monitoring_metric_series_pointer_events');
        Schema::dropIfExists('monitoring_configuration_snapshot_storage_events');
    }

    private function assertExistingEvidenceIntegrity(): void
    {
        if (DB::table('monitoring_configuration_snapshots')
            ->where(function ($query): void {
                $query->whereNotIn('storage_state', [
                    'available',
                    'integrity_failed',
                    'missing',
                    'unavailable',
                    'deleted',
                ])->orWhereRaw(
                    '(storage_state = ? AND payload_deleted_at IS NULL) OR (storage_state <> ? AND payload_deleted_at IS NOT NULL)',
                    ['deleted', 'deleted'],
                );
            })
            ->exists()) {
            throw new RuntimeException(
                'Configuration snapshot storage lifecycle is inconsistent. Reconcile the retained evidence before retrying.',
            );
        }

        if (DB::table('monitoring_metric_series')
            ->whereRaw(
                '(first_point_at IS NULL AND last_point_at IS NOT NULL)'
                .' OR (first_point_at IS NOT NULL AND last_point_at IS NULL)'
                .' OR first_point_at > last_point_at',
            )
            ->exists()) {
            throw new RuntimeException(
                'Metric series pointer lifecycle is inconsistent. Reconcile the retained evidence before retrying.',
            );
        }

        $invalidTombstone = DB::table('monitoring_retention_tombstones as tombstone')
            ->leftJoin('monitoring_metric_series as series', 'series.id', '=', 'tombstone.series_id')
            ->leftJoin(
                'monitoring_configuration_snapshots as snapshot',
                'snapshot.id',
                '=',
                'tombstone.snapshot_id',
            )
            ->where(function ($query): void {
                $query->whereRaw('(tombstone.series_id IS NULL) = (tombstone.snapshot_id IS NULL)')
                    ->orWhereRaw('tombstone.period_start > tombstone.period_end')
                    ->orWhere(function ($series): void {
                        $series->whereNotNull('tombstone.series_id')
                            ->where(function ($mismatch): void {
                                $mismatch->whereNull('series.id')
                                    ->orWhereColumn('series.site_id', '!=', 'tombstone.site_id')
                                    ->orWhereColumn('series.device_id', '!=', 'tombstone.device_id')
                                    ->orWhereRaw('NOT (series.monitor_id <=> tombstone.monitor_id)')
                                    ->orWhereColumn('series.data_class', '!=', 'tombstone.data_class')
                                    ->orWhereColumn('series.retention_tier', '!=', 'tombstone.retention_tier');
                            });
                    })
                    ->orWhere(function ($snapshot): void {
                        $snapshot->whereNotNull('tombstone.snapshot_id')
                            ->where(function ($mismatch): void {
                                $mismatch->whereNull('snapshot.id')
                                    ->orWhereColumn('snapshot.site_id', '!=', 'tombstone.site_id')
                                    ->orWhereColumn('snapshot.device_id', '!=', 'tombstone.device_id')
                                    ->orWhereNotNull('tombstone.monitor_id')
                                    ->orWhere('tombstone.data_class', '!=', 'configuration')
                                    ->orWhere('tombstone.retention_tier', '!=', 'configuration')
                                    ->orWhereRaw('NOT (tombstone.period_start <=> snapshot.captured_at)')
                                    ->orWhereRaw('NOT (tombstone.period_end <=> snapshot.captured_at)');
                            });
                    });
            })
            ->exists();

        if ($invalidTombstone) {
            throw new RuntimeException(
                'Monitoring retention tombstone lineage is inconsistent. Reconcile the retained evidence before retrying.',
            );
        }
    }

    private function installSnapshotGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_snapshots_before_update_guard
            BEFORE UPDATE ON monitoring_configuration_snapshots
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.id <=> NEW.id)
                    OR NOT (OLD.snapshot_uuid <=> NEW.snapshot_uuid)
                    OR NOT (OLD.site_id <=> NEW.site_id)
                    OR NOT (OLD.device_id <=> NEW.device_id)
                    OR NOT (OLD.source_kind <=> NEW.source_kind)
                    OR NOT (OLD.source <=> NEW.source)
                    OR NOT (OLD.storage_disk <=> NEW.storage_disk)
                    OR NOT (OLD.storage_path <=> NEW.storage_path)
                    OR NOT (OLD.storage_path_hash <=> NEW.storage_path_hash)
                    OR NOT (OLD.content_hash <=> NEW.content_hash)
                    OR NOT (OLD.configuration_hash <=> NEW.configuration_hash)
                    OR NOT (OLD.content_size <=> NEW.content_size)
                    OR NOT (OLD.mime_type <=> NEW.mime_type)
                    OR NOT (OLD.firmware_version <=> NEW.firmware_version)
                    OR NOT (OLD.captured_at <=> NEW.captured_at)
                    OR NOT (OLD.retention_policy_id <=> NEW.retention_policy_id)
                    OR NOT (OLD.previous_snapshot_id <=> NEW.previous_snapshot_id)
                    OR NOT (OLD.diff_summary <=> NEW.diff_summary)
                    OR NOT (OLD.created_by_user_id <=> NEW.created_by_user_id)
                    OR NOT (OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Configuration snapshot evidence is immutable.';
                END IF;

                IF OLD.storage_state = 'deleted' OR OLD.payload_deleted_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Deleted configuration snapshot history is immutable.';
                END IF;

                IF OLD.storage_state <=> NEW.storage_state
                    AND OLD.payload_deleted_at <=> NEW.payload_deleted_at THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Configuration snapshot updates require a storage lifecycle transition.';
                END IF;

                IF NEW.storage_state NOT IN ('available', 'integrity_failed', 'missing', 'unavailable', 'deleted') THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Configuration snapshot storage state is invalid.';
                END IF;

                IF NEW.storage_state = 'deleted' THEN
                    IF OLD.storage_state <> 'available'
                        OR NEW.payload_deleted_at IS NULL
                        OR NEW.payload_deleted_at < NEW.captured_at THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Configuration snapshot retention deletion is invalid.';
                    END IF;
                ELSEIF NEW.payload_deleted_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Configuration snapshot payload deletion requires the deleted state.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_snapshots_after_update_audit
            AFTER UPDATE ON monitoring_configuration_snapshots
            FOR EACH ROW
            BEGIN
                INSERT INTO monitoring_configuration_snapshot_storage_events (
                    snapshot_id,
                    from_storage_state,
                    to_storage_state,
                    from_payload_deleted_at,
                    to_payload_deleted_at,
                    transition_kind,
                    occurred_at
                ) VALUES (
                    NEW.id,
                    OLD.storage_state,
                    NEW.storage_state,
                    OLD.payload_deleted_at,
                    NEW.payload_deleted_at,
                    IF(NEW.storage_state = 'deleted', 'retention_deleted', 'storage_reconciled'),
                    CURRENT_TIMESTAMP(6)
                );
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_snapshots_before_delete_guard
            BEFORE DELETE ON monitoring_configuration_snapshots
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Configuration snapshot evidence cannot be deleted.';
            END
            SQL);
    }

    private function installTombstoneGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_tombstones_before_insert_guard
            BEFORE INSERT ON monitoring_retention_tombstones
            FOR EACH ROW
            BEGIN
                IF (NEW.series_id IS NULL) = (NEW.snapshot_id IS NULL)
                    OR NEW.period_start > NEW.period_end
                    OR NEW.deleted_at IS NULL
                    OR NEW.created_at IS NULL
                    OR NEW.updated_at IS NULL
                    OR CHAR_LENGTH(TRIM(NEW.job_reference)) = 0 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Monitoring retention tombstone evidence is incomplete.';
                END IF;

                IF NEW.series_id IS NOT NULL THEN
                    IF (SELECT COUNT(*)
                        FROM monitoring_metric_series AS series
                        WHERE series.id = NEW.series_id
                            AND series.site_id = NEW.site_id
                            AND series.device_id = NEW.device_id
                            AND series.monitor_id <=> NEW.monitor_id
                            AND series.data_class = NEW.data_class
                            AND series.retention_tier = NEW.retention_tier) <> 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Metric retention tombstone lineage is invalid.';
                    END IF;
                ELSEIF (SELECT COUNT(*)
                    FROM monitoring_configuration_snapshots AS snapshot
                    WHERE snapshot.id = NEW.snapshot_id
                        AND snapshot.site_id = NEW.site_id
                        AND snapshot.device_id = NEW.device_id
                        AND NEW.monitor_id IS NULL
                        AND NEW.data_class = 'configuration'
                        AND NEW.retention_tier = 'configuration'
                        AND NEW.period_start <=> snapshot.captured_at
                        AND NEW.period_end <=> snapshot.captured_at) <> 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Snapshot retention tombstone lineage is invalid.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_tombstones_before_update_guard
            BEFORE UPDATE ON monitoring_retention_tombstones
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Monitoring retention tombstone evidence is immutable.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_tombstones_before_delete_guard
            BEFORE DELETE ON monitoring_retention_tombstones
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Monitoring retention tombstone evidence cannot be deleted.';
            END
            SQL);
    }

    private function installSeriesGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_series_before_update_guard
            BEFORE UPDATE ON monitoring_metric_series
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.id <=> NEW.id)
                    OR NOT (OLD.site_id <=> NEW.site_id)
                    OR NOT (OLD.device_id <=> NEW.device_id)
                    OR NOT (OLD.monitor_id <=> NEW.monitor_id)
                    OR NOT (OLD.metric <=> NEW.metric)
                    OR NOT (OLD.dimensions <=> NEW.dimensions)
                    OR NOT (OLD.dimensions_hash <=> NEW.dimensions_hash)
                    OR NOT (OLD.unit <=> NEW.unit)
                    OR NOT (OLD.source <=> NEW.source)
                    OR NOT (OLD.data_class <=> NEW.data_class)
                    OR NOT (OLD.privacy_class <=> NEW.privacy_class)
                    OR NOT (OLD.retention_tier <=> NEW.retention_tier)
                    OR NOT (OLD.external_key <=> NEW.external_key)
                    OR NOT (OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Metric series identity evidence is immutable.';
                END IF;

                IF OLD.first_point_at <=> NEW.first_point_at
                    AND OLD.last_point_at <=> NEW.last_point_at THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Metric series updates require a pointer lifecycle transition.';
                END IF;

                IF (NEW.first_point_at IS NULL) <> (NEW.last_point_at IS NULL)
                    OR NEW.first_point_at > NEW.last_point_at THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Metric series pointer range is invalid.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_series_after_update_audit
            AFTER UPDATE ON monitoring_metric_series
            FOR EACH ROW
            BEGIN
                INSERT INTO monitoring_metric_series_pointer_events (
                    series_id,
                    from_first_point_at,
                    to_first_point_at,
                    from_last_point_at,
                    to_last_point_at,
                    transition_kind,
                    occurred_at
                ) VALUES (
                    NEW.id,
                    OLD.first_point_at,
                    NEW.first_point_at,
                    OLD.last_point_at,
                    NEW.last_point_at,
                    CASE
                        WHEN OLD.first_point_at IS NULL AND NEW.first_point_at IS NOT NULL
                            THEN 'initialized'
                        WHEN NEW.first_point_at IS NULL
                            THEN 'retention_purged'
                        WHEN NEW.first_point_at <= OLD.first_point_at
                            AND NEW.last_point_at >= OLD.last_point_at
                            THEN 'range_extended'
                        WHEN NEW.first_point_at >= OLD.first_point_at
                            AND NEW.last_point_at <= OLD.last_point_at
                            THEN 'retention_trimmed'
                        ELSE 'range_reconciled'
                    END,
                    CURRENT_TIMESTAMP(6)
                );
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_series_before_delete_guard
            BEFORE DELETE ON monitoring_metric_series
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Metric series business-record pointers cannot be deleted.';
            END
            SQL);
    }

    private function installTransitionEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_snapshot_events_update_guard
            BEFORE UPDATE ON monitoring_configuration_snapshot_storage_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Configuration snapshot storage transition evidence is immutable.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_snapshot_events_delete_guard
            BEFORE DELETE ON monitoring_configuration_snapshot_storage_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Configuration snapshot storage transition evidence cannot be deleted.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_series_events_update_guard
            BEFORE UPDATE ON monitoring_metric_series_pointer_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Metric series pointer transition evidence is immutable.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER monitoring_series_events_delete_guard
            BEFORE DELETE ON monitoring_metric_series_pointer_events
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Metric series pointer transition evidence cannot be deleted.';
            END
            SQL);
    }

    private function dropGuards(): void
    {
        foreach ([
            self::SNAPSHOT_UPDATE_GUARD,
            self::SNAPSHOT_UPDATE_AUDIT,
            self::SNAPSHOT_DELETE_GUARD,
            self::SNAPSHOT_EVENT_UPDATE_GUARD,
            self::SNAPSHOT_EVENT_DELETE_GUARD,
            self::TOMBSTONE_INSERT_GUARD,
            self::TOMBSTONE_UPDATE_GUARD,
            self::TOMBSTONE_DELETE_GUARD,
            self::SERIES_UPDATE_GUARD,
            self::SERIES_UPDATE_AUDIT,
            self::SERIES_DELETE_GUARD,
            self::SERIES_EVENT_UPDATE_GUARD,
            self::SERIES_EVENT_DELETE_GUARD,
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
