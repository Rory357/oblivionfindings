<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class AccessControlScheduleRevision extends Model
{
    public $timestamps = false;

    protected $table = 'access_control_schedule_revisions';

    protected $fillable = [
        'access_schedule_id',
        'version',
        'action',
        'snapshot',
        'change_reason',
        'active_credentials_affected',
        'recorded_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
        'active_credentials_affected' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new UnexpectedValueException('Access-control schedule revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control schedule revisions are retained as immutable audit history.');
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AccessControlSchedule::class, 'access_schedule_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
