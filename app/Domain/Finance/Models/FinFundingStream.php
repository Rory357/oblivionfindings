<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinFundingStream extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_funding_streams';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'funder_type',
        'contact_name',
        'contact_email',
        'default_revenue_account_id',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function defaultRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'default_revenue_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfFunderType($query, string $type)
    {
        return $query->where('funder_type', $type);
    }
}
