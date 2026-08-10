<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConfigurationSnapshot extends Model
{
    private const array STORAGE_STATES = [
        'available',
        'integrity_failed',
        'missing',
        'unavailable',
        'deleted',
    ];

    protected $table = 'monitoring_configuration_snapshots';

    protected $fillable = [
        'snapshot_uuid',
        'site_id',
        'device_id',
        'source_kind',
        'source',
        'storage_disk',
        'storage_path',
        'storage_path_hash',
        'storage_state',
        'content_hash',
        'configuration_hash',
        'content_size',
        'mime_type',
        'firmware_version',
        'captured_at',
        'payload_deleted_at',
        'retention_policy_id',
        'previous_snapshot_id',
        'diff_summary',
        'created_by_user_id',
    ];

    protected $hidden = [
        'storage_path',
    ];

    protected $casts = [
        'content_size' => 'integer',
        'captured_at' => 'immutable_datetime',
        'payload_deleted_at' => 'immutable_datetime',
        'diff_summary' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $snapshot): void {
            $changed = array_values(array_diff(
                array_keys($snapshot->getDirty()),
                ['updated_at'],
            ));
            $unexpected = array_diff($changed, ['storage_state', 'payload_deleted_at']);
            if ($unexpected !== []) {
                throw new \UnexpectedValueException('Configuration snapshot evidence is immutable.');
            }

            if ($snapshot->getRawOriginal('storage_state') === 'deleted'
                || $snapshot->getRawOriginal('payload_deleted_at') !== null) {
                throw new \UnexpectedValueException('Deleted configuration snapshot history is immutable.');
            }

            if ($changed === []) {
                throw new \UnexpectedValueException(
                    'Configuration snapshot updates require a storage lifecycle transition.',
                );
            }

            $state = (string) $snapshot->storage_state;
            if (! in_array($state, self::STORAGE_STATES, true)) {
                throw new \UnexpectedValueException('Configuration snapshot storage state is invalid.');
            }

            if ($state === 'deleted') {
                if ($snapshot->getRawOriginal('storage_state') !== 'available'
                    || ! $snapshot->isDirty('storage_state')
                    || ! $snapshot->isDirty('payload_deleted_at')
                    || $snapshot->payload_deleted_at === null
                    || $snapshot->payload_deleted_at->lessThan($snapshot->captured_at)) {
                    throw new \UnexpectedValueException(
                        'Configuration snapshot retention deletion is invalid.',
                    );
                }

                return;
            }

            if ($snapshot->payload_deleted_at !== null || $snapshot->isDirty('payload_deleted_at')) {
                throw new \UnexpectedValueException(
                    'Configuration snapshot payload deletion requires the deleted state.',
                );
            }

            if (! $snapshot->isDirty('storage_state')) {
                throw new \UnexpectedValueException(
                    'Configuration snapshot updates require a storage lifecycle transition.',
                );
            }
        });

        self::deleting(function (): void {
            throw new \UnexpectedValueException('Configuration snapshot evidence cannot be deleted.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionPolicy::class, 'retention_policy_id');
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
