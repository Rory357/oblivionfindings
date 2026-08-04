<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteStaffRequirement extends Model
{
    use HasFactory;

    protected $table = 'site_staff_requirements';

    protected $fillable = [
        'site_id',
        'requirement_name',
        'category',
        'description',
        'certification_required',
        'expiry_period_months',
        'is_active',
    ];

    protected $casts = [
        'certification_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeMandatory($query)
    {
        return $query->where('category', 'mandatory');
    }
}
