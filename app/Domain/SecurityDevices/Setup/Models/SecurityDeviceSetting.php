<?php

namespace App\Domain\SecurityDevices\Setup\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One organisation-wide Security & devices switch (Setup). Read and changed only through
 * SecurityDevicesSettings, which knows each switch's default and who may change it.
 *
 * @property string $key
 * @property mixed $value
 */
final class SecurityDeviceSetting extends Model
{
    protected $table = 'security_device_settings';

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    protected $casts = [
        'value' => 'json',
    ];
}
