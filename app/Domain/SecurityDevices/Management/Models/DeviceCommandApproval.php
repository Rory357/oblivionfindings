<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class DeviceCommandApproval extends Model
{
    protected $table = 'device_command_approvals';

    protected $fillable = [
        'device_command_request_id', 'decided_by_user_id', 'decision', 'comment', 'decided_at',
    ];

    protected $casts = [
        'decision' => CommandApprovalDecision::class,
        'decided_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            $requesterId = DeviceCommandRequest::query()
                ->whereKey($approval->device_command_request_id)
                ->value('requested_by_user_id');
            if ($requesterId !== null && (int) $requesterId === (int) $approval->decided_by_user_id) {
                throw new UnexpectedValueException('A device command requester cannot record its approval decision.');
            }
        });
        static::updating(function (): never {
            throw new UnexpectedValueException('Device command approval decisions are immutable.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command approval decisions are immutable.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
