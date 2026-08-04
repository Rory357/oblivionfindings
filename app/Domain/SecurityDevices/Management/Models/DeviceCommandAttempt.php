<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use UnexpectedValueException;

class DeviceCommandAttempt extends Model
{
    public const array IMMUTABLE_IDENTITY_ATTRIBUTES = [
        'device_command_request_id', 'attempt_uuid', 'attempt_number', 'runtime',
    ];

    protected $table = 'device_command_attempts';

    protected $fillable = [
        'device_command_request_id', 'attempt_uuid', 'attempt_number', 'status', 'runtime',
        'provider_request_reference', 'safe_result_summary', 'evidence_reference',
        'safe_failure_reason', 'accepted_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'status' => CommandAttemptStatus::class,
        'safe_result_summary' => 'array',
        'accepted_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            $attempt->attempt_uuid ??= (string) Str::orderedUuid();
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command execution attempts are immutable evidence.');
        });
    }

    protected function performUpdate(Builder $query)
    {
        if (collect(self::IMMUTABLE_IDENTITY_ATTRIBUTES)->contains(fn (string $attribute): bool => $this->isDirty($attribute))) {
            throw new UnexpectedValueException('Device command attempt identity is immutable.');
        }

        $previousStatus = CommandAttemptStatus::from((string) $this->getRawOriginal('status'));
        $nextStatus = $this->status;
        if (! $previousStatus->canTransitionTo($nextStatus)) {
            throw new UnexpectedValueException("Invalid device command attempt transition from {$previousStatus->value} to {$nextStatus->value}.");
        }
        if ($previousStatus->isTerminal() && $this->isDirty()) {
            throw new UnexpectedValueException('Terminal device command execution evidence is immutable.');
        }

        return parent::performUpdate($query);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }
}
