<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'tenant_id',
        'phone',
        'email',
        'manager_name',
        'manager_phone',
        'after_hours_phone',
        'emergency_plan_location',
        'medication_storage_location',
        'notes',
        'address_line_1',
        'address_line_2',
        'suburb',
        'city',
        'postcode',
        'country',
        'region',
        'latitude',
        'longitude',
        'access_instructions',
        'is_high_risk',
        'is_high_needs',
        'risk_notes',
        'risk_review_date',
        'is_active',
        'onboarding_completed_at',
        'onboarding_progress',
        'primary_contact_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_high_risk' => 'boolean',
        'is_high_needs' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'risk_review_date' => 'date',
        'onboarding_completed_at' => 'datetime',
        'onboarding_progress' => 'array',
    ];

    // Relationships
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SiteContact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SiteDocument::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_contact_user_id');
    }

    // New relationships for Sites module
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(SiteCalendarEvent::class);
    }

    public function hazards(): HasMany
    {
        return $this->hasMany(SiteHazard::class);
    }

    public function checklistAssignments(): HasMany
    {
        return $this->hasMany(SiteChecklistAssignment::class);
    }

    public function checklistRuns(): HasMany
    {
        return $this->hasMany(SiteChecklistRun::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(SiteVendor::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(SiteCredential::class);
    }

    public function houseRooms(): HasMany
    {
        return $this->hasMany(SiteHouseRoom::class);
    }

    public function hoResources(): HasMany
    {
        return $this->hasMany(SiteHoResource::class);
    }

    public function hoSettings(): HasMany
    {
        return $this->hasMany(SiteHoSetting::class);
    }

    public function facilityZones(): HasMany
    {
        return $this->hasMany(SiteFacilityZone::class);
    }

    public function inspectionSchedules(): HasMany
    {
        return $this->hasMany(SiteInspectionSchedule::class);
    }

    public function inspectionRecords(): HasMany
    {
        return $this->hasMany(SiteInspectionRecord::class);
    }

    public function siteRooms(): HasMany
    {
        return $this->hasMany(SiteRoom::class);
    }

    public function locationHardware(): HasMany
    {
        return $this->hasMany(LocationHardware::class);
    }

    public function integrationConfigs(): HasMany
    {
        return $this->hasMany(Integration\IntegrationSiteConfig::class);
    }

    public function integrationEvents(): HasMany
    {
        return $this->hasMany(Integration\IntegrationEvent::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(SiteCertification::class);
    }

    public function complianceChecks(): HasMany
    {
        return $this->hasMany(SiteComplianceCheck::class);
    }

    public function staffRequirements(): HasMany
    {
        return $this->hasMany(SiteStaffRequirement::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(SiteFeedback::class);
    }

    public function serviceContexts(): HasMany
    {
        return $this->hasMany(\App\Models\ServiceContext::class);
    }

    // Accessors
    public function getAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->suburb,
            $this->city,
            $this->postcode,
            $this->country,
        ], fn($v) => is_string($v) && trim($v) !== '');

        return implode(', ', $parts);
    }

    public function getDisplayTypeAttribute(): string
    {
        return match ($this->type) {
            'head_office' => 'Head Office',
            'house' => 'House',
            'facility' => 'Facilities',
            default => 'Site',
        };
    }

    public function getRiskFlagsAttribute(): array
    {
        $flags = [];
        if ($this->is_high_risk) {
            $flags[] = 'High Risk';
        }
        if ($this->is_high_needs) {
            $flags[] = 'High Needs';
        }
        return $flags;
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeHighRisk($query)
    {
        return $query->where(function ($q) {
            $q->where('is_high_risk', true)
              ->orWhere('is_high_needs', true);
        });
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }
        return $query->where('tenant_id', $tenantId);
    }
}
