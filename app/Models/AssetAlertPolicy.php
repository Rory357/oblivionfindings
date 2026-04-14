<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated PR5: Superseded by SignalRule for asset alert routing.
 *             Asset alert policies are no longer used in active runtime.
 *             This model remains only for historical schema compatibility
 *             with archived asset_alert rows that may still reference a policy.
 */
class AssetAlertPolicy extends Model
{
    protected $fillable = [
        'name',
        'policy_type',
        'severity',
        'conditions',
        'actions',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
    ];

    public function alerts(): HasMany
    {
        return $this->hasMany(AssetAlert::class);
    }
}
