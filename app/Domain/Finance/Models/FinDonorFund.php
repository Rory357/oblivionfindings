<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class FinDonorFund extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_donor_funds';

    protected $fillable = [
        'organization_id',
        'fund_code',
        'fund_name',
        'donor_name',
        'donor_contact',
        'fund_type',
        'gl_account_id',
        'funding_stream_id',
        'total_received',
        'total_spent',
        'total_committed',
        'available_balance',
        'budget_amount',
        'start_date',
        'end_date',
        'restrictions',
        'reporting_requirements',
        'next_report_due',
        'status',
        'is_restricted',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_report_due' => 'date',
        'total_received' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'total_committed' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'is_restricted' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(FinDonorFundTransaction::class, 'fund_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(FinDonorFundReport::class, 'fund_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'gl_account_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeRestricted($query)
    {
        return $query->where('is_restricted', true);
    }

    public function scopeUnrestricted($query)
    {
        return $query->where('is_restricted', false);
    }

    public function scopeExpiringSoon($query, int $days = 90)
    {
        return $query->whereNotNull('end_date')
            ->where('end_date', '<=', Carbon::now()->addDays($days))
            ->where('end_date', '>=', Carbon::now())
            ->where('status', 'active');
    }
}
