<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'severity',
        'default_description',
        'prompts',
        'checklist',
        'is_active',
    ];

    protected $casts = [
        'prompts' => 'array',
        'checklist' => 'array',
        'is_active' => 'boolean',
    ];

    public function incidents(): HasMany
    {
        return $this->hasMany(ClientIncident::class, 'template_id');
    }
}
