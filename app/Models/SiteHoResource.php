<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHoResource extends Model
{
    use HasFactory;

    protected $table = 'site_ho_resources';

    protected $fillable = [
        'site_id',
        'name',
        'resource_type',
        'capacity',
        'amenities',
        'calendar_email',
        'calendar_sync_token',
        'is_bookable',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'amenities' => 'array',
        'is_bookable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBookable($query)
    {
        return $query->where('is_bookable', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('resource_type', $type);
    }
}
