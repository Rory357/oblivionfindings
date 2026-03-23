<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceAgreement extends Model
{
    use HasFactory;
    use AuditableChanges;

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
        'terms',
        'notes',
        'signed_at',
        'signed_by',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'signed_at' => 'datetime',
        'total_budget' => 'decimal:2',
        'budget_used' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lineItems()
    {
        return $this->hasMany(ServiceAgreementLineItem::class);
    }

    public function fundingClaims()
    {
        return $this->hasMany(FundingClaim::class);
    }

    public function getBudgetRemainingAttribute()
    {
        return $this->total_budget - $this->budget_used;
    }

    public function getBudgetUtilisationPercentAttribute()
    {
        return $this->total_budget > 0
            ? round(($this->budget_used / $this->total_budget) * 100, 1)
            : 0;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query)
    {
        return $query->where('ends_at', '<=', now()->addDays(30))
            ->where('status', 'active');
    }
}
