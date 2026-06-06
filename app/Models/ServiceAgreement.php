<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAgreement extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'client_id',
        'title',
        'reference_number',
        'status',
        'agreement_type',
        'funding_body',
        'funding_reference',
        'starts_at',
        'ends_at',
        'total_budget',
        'budget_used',
        'hourly_rate',
        'daily_rate',
        'total_hours',
        'hours_used',
        'gst_inclusive',
        'funding_type',
        'service_level',
        'allocated_hours_per_week',
        'nasc_assessor_name',
        'nasc_support_package_ref',
        'support_needs_level',
        'whaikaha_reference',
        'funder_contact_name',
        'funder_contact_email',
        'funder_contact_phone',
        'client_signatory',
        'provider_signatory',
        'terms',
        'notes',
        'signed_at',
        'signed_by',
        'created_by',
        'submitted_for_approval_at',
        'submitted_for_approval_by',
        'approved_at',
        'approved_by',
        'nasc_assessment_date',
        'funding_approved_date',
        'signed_date',
        'first_service_date',
        'review_due_date',
        'renewal_date',
        'terminated_at',
        'terminated_reason',
        'suspended_at',
        'suspended_reason',
        'resumed_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'signed_at' => 'datetime',
        'total_budget' => 'decimal:2',
        'budget_used' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'hours_used' => 'decimal:2',
        'gst_inclusive' => 'boolean',
        'allocated_hours_per_week' => 'decimal:2',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'nasc_assessment_date' => 'date',
        'funding_approved_date' => 'date',
        'signed_date' => 'date',
        'first_service_date' => 'date',
        'review_due_date' => 'date',
        'renewal_date' => 'date',
        'terminated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_for_approval_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lineItems()
    {
        return $this->hasMany(ServiceAgreementLineItem::class);
    }

    public function fundingClaims()
    {
        return $this->hasMany(FundingClaim::class);
    }

    public function respiteBookingRequests()
    {
        return $this->hasMany(RespiteBookingRequest::class);
    }

    public function respiteBookings()
    {
        return $this->hasMany(RespiteBooking::class);
    }

    public function statusChanges()
    {
        return $this->hasMany(ServiceAgreementStatusChange::class);
    }

    public function getBudgetUsedFromItemsAttribute()
    {
        return $this->lineItems()->sum('budget_used');
    }

    public function getBudgetAllocatedFromItemsAttribute()
    {
        return $this->lineItems()->sum('budget_allocated');
    }

    public function getBudgetRemainingAttribute()
    {
        $used = $this->budget_used > 0 ? $this->budget_used : $this->budget_used_from_items;
        return $this->total_budget - $used;
    }

    public function getBudgetUtilisationPercentAttribute()
    {
        $used = $this->budget_used > 0 ? $this->budget_used : $this->budget_used_from_items;
        return $this->total_budget > 0
            ? round(($used / $this->total_budget) * 100, 1)
            : 0;
    }

    public function getHoursRemainingAttribute()
    {
        return ($this->total_hours ?? 0) - ($this->hours_used ?? 0);
    }

    public function getHoursUtilisationPercentAttribute()
    {
        if (! $this->total_hours || $this->total_hours <= 0) {
            return 0;
        }

        return round(($this->hours_used / $this->total_hours) * 100, 1);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function rates()
    {
        return $this->hasMany(ServiceAgreementRate::class);
    }

    public function scopeExpiringSoon($query)
    {
        return $query->where('ends_at', '<=', now()->addDays(30))
            ->where('status', 'active');
    }
}
