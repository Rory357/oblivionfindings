<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'default_risk_level',
        'requires_inspection_default',
        'requires_maintenance_default',
        'policy_profile_id',
    ];

    protected $casts = [
        'requires_inspection_default' => 'boolean',
        'requires_maintenance_default' => 'boolean',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }
}
