<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessControlCredential extends Model
{
    public const HOLDER_STAFF = 'staff';

    public const HOLDER_CLIENT = 'client';

    public const VALID_HOLDER_TYPES = [self::HOLDER_STAFF, self::HOLDER_CLIENT];

    protected $table = 'access_control_credentials';

    protected $fillable = [
        'site_id',
        'access_schedule_id',
        'label',
        'holder_type',
        'holder_id',
        'reference_key',
        'status',
        'valid_from',
        'valid_until',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AccessControlSchedule::class, 'access_schedule_id');
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'access_control_credential_device', 'access_credential_id')
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
