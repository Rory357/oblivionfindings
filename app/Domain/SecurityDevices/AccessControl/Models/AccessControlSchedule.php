<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'created_by_user_id',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
    ];

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
}
