<?php

namespace App\Models;

use App\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsentType extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'name',
        'category',
        'description',
        'purpose',
        'legal_basis',
        'mandatory',
        'requires_capacity_assessment',
        'validity_period_months',
        'withdrawable',
        'withdrawal_implications',
        'version',
        'active',
    ];

    protected $casts = [
        'mandatory' => 'boolean',
        'requires_capacity_assessment' => 'boolean',
        'withdrawable' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * Versions of this consent type.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ConsentTypeVersion::class);
    }

    /**
     * Client consents using this type.
     */
    public function clientConsents(): HasMany
    {
        return $this->hasMany(ClientConsent::class);
    }

    /**
     * Get the current version.
     */
    public function currentVersion()
    {
        return $this->hasOne(ConsentTypeVersion::class)
            ->where('effective_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->latest('version');
    }

    /**
     * Scope: Active consent types.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: Mandatory consent types.
     */
    public function scopeMandatory($query)
    {
        return $query->where('mandatory', true);
    }

    /**
     * Scope: By category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Check if consent requires capacity assessment.
     */
    public function requiresCapacityAssessment(): bool
    {
        return $this->requires_capacity_assessment;
    }

    /**
     * Check if consent is withdrawable.
     */
    public function isWithdrawable(): bool
    {
        return $this->withdrawable;
    }
}
