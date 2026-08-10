<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use UnexpectedValueException;

class DeviceCommandBatch extends Model
{
    protected $table = 'device_command_batches';

    protected $fillable = [
        'batch_uuid',
        'requested_by_user_id',
        'workspace',
        'capability',
        'capability_version',
        'risk',
        'confirmation_mode',
        'reason',
        'safe_parameter_summary',
        'idempotency_key',
        'contract_hash',
        'target_count',
        'included_count',
        'excluded_count',
        'site_count',
        'impact_acknowledged_at',
    ];

    protected $hidden = ['contract_hash'];

    protected $casts = [
        'capability_version' => 'integer',
        'risk' => CommandRisk::class,
        'confirmation_mode' => CommandConfirmationMode::class,
        'safe_parameter_summary' => 'array',
        'target_count' => 'integer',
        'included_count' => 'integer',
        'excluded_count' => 'integer',
        'site_count' => 'integer',
        'impact_acknowledged_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $batch->batch_uuid ??= (string) Str::orderedUuid();
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command batches are retained as immutable audit evidence.');
        });
    }

    protected function performUpdate(Builder $query): never
    {
        throw new UnexpectedValueException('Device command batch contracts are immutable.');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(DeviceCommandBatchTarget::class);
    }
}
