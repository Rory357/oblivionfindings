<?php

namespace App\Models;

use App\Support\SecurityPolicy;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        // Supports either scalar (e.g. "Patient") or object/array values.
        'value' => 'json',
    ];

    protected static function booted(): void
    {
        $bustPolicyCache = function (self $setting): void {
            if (in_array($setting->key, SecurityPolicy::keys(), true)) {
                SecurityPolicy::flushCache();
            }
        };

        static::saved($bustPolicyCache);
        static::deleted($bustPolicyCache);
    }
}
