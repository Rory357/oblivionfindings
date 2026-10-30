<?php

namespace App\Domain\Referrals\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReferralSettingsVersion extends Model
{
    protected $table = 'care_referral_settings_versions';

    protected $fillable = [
        'version',
        'state',
        'base_version_id',
        'restored_from_version_id',
        'configuration',
        'reason',
        'publication_note',
        'created_by',
        'published_by',
        'discarded_by',
        'published_at',
        'effective_at',
        'discarded_at',
        'discard_reason',
        'request_key',
        'request_hash',
    ];

    protected $hidden = ['configuration', 'request_key', 'request_hash'];

    protected $casts = [
        'configuration' => 'encrypted:array',
        'published_at' => 'immutable_datetime',
        'effective_at' => 'immutable_datetime',
        'discarded_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('state') === 'published') {
                throw new LogicException('Publish a new settings version; historical configuration is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Settings history cannot be deleted.'));
    }
}
