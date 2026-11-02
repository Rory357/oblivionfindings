<?php

namespace App\Domain\SecurityDevices\Hubs\Models;

use App\Domain\Monitoring\Models\CollectorEnrollment;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time setup code (D2: one computer, 24 hours, can be cancelled). It travels in the
 * installer's file name, so nothing is typed and a signed installer stays valid.
 *
 * @property int $id
 * @property string $role
 * @property string $platform
 * @property int $site_id
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $redeemed_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 */
class SetupCode extends Model
{
    protected $table = 'security_device_setup_codes';

    protected $fillable = [
        'role', 'platform', 'install_mode', 'site_id', 'issued_by_user_id', 'collector_enrollment_id', 'replaces_collector_id',
        'intended_name', 'code_hash', 'code_ciphertext', 'profile_ciphertext', 'expires_at',
    ];

    protected $hidden = ['code_hash', 'code_ciphertext', 'profile_ciphertext'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'redeemed_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'redeem_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CollectorEnrollment::class, 'collector_enrollment_id');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'replaces_collector_id');
    }

    /** waiting | used | expired | cancelled */
    public function state(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->consumed_at !== null => 'used',
            $this->expires_at->isPast() => 'expired',
            default => 'waiting',
        };
    }
}
