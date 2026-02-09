<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteVendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'service_type',
        'company_name',
        'contact_name',
        'phone',
        'after_hours_phone',
        'email',
        'account_number',
        'notes',
        'preferred_contact_method',
        'is_preferred',
        'is_active',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(SiteCredential::class, 'vendor_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePreferred($query)
    {
        return $query->where('is_preferred', true);
    }

    public function scopeByServiceType($query, string $type)
    {
        return $query->where('service_type', $type);
    }
}
