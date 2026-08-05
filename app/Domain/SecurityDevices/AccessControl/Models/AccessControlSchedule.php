<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

class AccessControlSchedule extends Model
{
    protected $table = 'access_control_schedules';

    protected $fillable = [
        'site_id',
        'name',
        'timezone',
        'days',
        'starts_at',
        'ends_at',
        'is_active',
        'version',
        'provider_reconciliation_status',
        'provider_reconciliation_required_at',
        'created_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'provider_reconciliation_required_at' => 'immutable_datetime',
        'deactivated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control schedules cannot be hard deleted. Deactivate the schedule instead.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AccessControlCredential::class, 'access_schedule_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AccessControlScheduleRevision::class, 'access_schedule_id')->orderByDesc('version');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }
}
