<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItQueue extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'team_id',
        'key',
        'name',
        'description',
        'filter_rules',
        'is_active',
    ];

    protected $casts = [
        'filter_rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(ItTeam::class, 'team_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ItTicket::class, 'queue_id');
    }
}
