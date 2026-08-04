<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class DeviceCommandReconciliation extends Model
{
    protected $table = 'device_command_reconciliations';

    protected $fillable = [
        'device_command_request_id', 'device_command_attempt_id', 'outcome', 'expected_state',
        'observed_state', 'observation_reference', 'safe_evidence_summary', 'observed_at',
    ];

    protected $casts = [
        'outcome' => CommandReconciliationOutcome::class,
        'expected_state' => 'array',
        'observed_state' => 'array',
        'observed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new UnexpectedValueException('Device command reconciliations are immutable evidence.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command reconciliations are immutable evidence.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandAttempt::class, 'device_command_attempt_id');
    }
}
