<?php

namespace App\Domain\SecurityDevices\Cctv\Models;

use Illuminate\Database\Eloquent\Model;

final class CctvSource extends Model
{
    protected $table = 'cctv_sources';

    protected $guarded = [];

    protected $casts = ['revision' => 'integer', 'enabled' => 'boolean', 'configuration' => 'encrypted:array', 'verified_at' => 'immutable_datetime', 'stream_verified_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $source): void {
            if ($source->isDirty('provider')) {
                throw new \LogicException('Camera source provider is immutable.');
            }
            if ($source->getRawOriginal('provider') === 'hikvision') {
                foreach (['provider', 'discovery_request_id', 'camera_id', 'recorder_device_id', 'site_id', 'collector_id', 'discovery_scope_id', 'actor_id', 'revision', 'fingerprint', 'provider_fingerprint', 'configuration', 'verified_at'] as $field) {
                    if ($source->isDirty($field)) {
                        throw new \LogicException('Reviewed camera source identity is immutable.');
                    }
                }
            }
        });
        self::deleting(function (self $source): void {
            if ($source->provider === 'hikvision') {
                throw new \LogicException('Reviewed camera sources are retained as evidence.');
            }
        });
    }

    protected $hidden = ['configuration'];
}
