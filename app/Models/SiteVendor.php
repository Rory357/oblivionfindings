<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteVendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'tenant_id',
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
        'hs_induction_completed',
        'hs_induction_date',
        'hs_induction_completed_by',
        'hs_induction_document_path',
        'qualifications_verified',
        'qualifications_notes',
        'insurance_verified',
        'insurance_expiry',
        'contract_renewal_date',
        'next_visit_date',
        'insurance_provider',
        'insurance_policy_number',
        'site_specific_hs_plan',
        'hs_performance_rating',
        'hs_last_reviewed_at',
    ];

    protected $casts = [
        'is_preferred' => 'boolean',
        'is_active' => 'boolean',
        'hs_induction_completed' => 'boolean',
        'hs_induction_date' => 'date',
        'qualifications_verified' => 'boolean',
        'insurance_verified' => 'boolean',
        'insurance_expiry' => 'date',
        'contract_renewal_date' => 'date',
        'next_visit_date' => 'date',
        'hs_last_reviewed_at' => 'date',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function hsInductionCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hs_induction_completed_by');
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
