<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteComplianceTemplate extends Model
{
    use HasFactory;

    protected $table = 'site_compliance_templates';

    protected $fillable = [
        'organization_id',
        'name',
        'category',
        'description',
        'checklist_items',
        'frequency',
        'regulatory_reference',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'checklist_items' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->where(function ($q) use ($orgId) {
            $q->whereNull('organization_id');
            if ($orgId) {
                $q->orWhere('organization_id', $orgId);
            }
        });
    }
}
