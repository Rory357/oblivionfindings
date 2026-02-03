<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
