<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetChecklistTemplate extends Model
{
    use WritesLegacyStorageContext;

    protected $fillable = [
        'name',
        'type',
        'items',
        'is_active',
    ];

    protected $casts = [
        'items' => 'array',
        'is_active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(FleetChecklistRun::class, 'template_id');
    }
}
