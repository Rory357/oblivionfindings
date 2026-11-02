<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A camera or Protect console address and the certificate key a Site trusts for it (Phase A, item A5).
 *
 * @property int $id
 * @property int $site_id
 * @property string $host
 * @property int $port
 * @property string|null $trusted_pin
 * @property string|null $trusted_fingerprint
 * @property \Illuminate\Support\Carbon|null $trusted_at
 * @property int|null $trusted_by_user_id
 * @property string|null $offered_pin
 * @property string|null $offered_fingerprint
 * @property string|null $offered_subject
 * @property \Illuminate\Support\Carbon|null $offered_valid_to
 * @property \Illuminate\Support\Carbon|null $offered_at
 */
final class CameraEndpointTrust extends Model
{
    protected $table = 'camera_endpoint_trusts';

    protected $fillable = [
        'site_id',
        'host',
        'port',
        'trusted_pin',
        'trusted_fingerprint',
        'trusted_at',
        'trusted_by_user_id',
        'offered_pin',
        'offered_fingerprint',
        'offered_subject',
        'offered_valid_to',
        'offered_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'trusted_at' => 'datetime',
            'offered_valid_to' => 'datetime',
            'offered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function trustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trusted_by_user_id');
    }
}
