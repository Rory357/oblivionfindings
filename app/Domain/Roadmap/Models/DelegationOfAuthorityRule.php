<?php

namespace App\Domain\Roadmap\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelegationOfAuthorityRule extends Model
{
    use HasFactory;

    protected $table = 'roadmap_delegation_authority_rules';

    protected $fillable = [
        'tenant_id',
        'scope',
        'amount_min',
        'amount_max',
        'risk_min',
        'risk_max',
        'required_approver_role',
        'escalation_role',
        'active_from',
        'active_to',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'amount_min' => 'decimal:2',
        'amount_max' => 'decimal:2',
        'risk_min' => 'decimal:2',
        'risk_max' => 'decimal:2',
        'active_from' => 'date',
        'active_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('active_from')->orWhere('active_from', '<=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('active_to')->orWhere('active_to', '>=', now()->toDateString());
            });
    }

    public function matches(float $amount, ?float $riskScore = null): bool
    {
        $amountMinOk = $this->amount_min === null || $amount >= (float) $this->amount_min;
        $amountMaxOk = $this->amount_max === null || $amount <= (float) $this->amount_max;

        $riskMinOk = $this->risk_min === null || $riskScore === null || $riskScore >= (float) $this->risk_min;
        $riskMaxOk = $this->risk_max === null || $riskScore === null || $riskScore <= (float) $this->risk_max;

        return $amountMinOk && $amountMaxOk && $riskMinOk && $riskMaxOk;
    }
}
