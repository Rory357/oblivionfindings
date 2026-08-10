<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SnmpCompatibilityException extends Model
{
    protected $table = 'monitoring_snmp_compatibility_exceptions';

    protected $fillable = [
        'site_id',
        'device_id',
        'version',
        'credential_reference',
        'owner_user_id',
        'reason',
        'expires_at',
        'migration_status',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
