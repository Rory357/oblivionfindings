<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinGstReturn extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_gst_returns';

    protected $fillable = [
        'organization_id',
        'period_start',
        'period_end',
        'filing_frequency',
        'basis',
        'total_sales',
        'total_gst_collected',
        'total_purchases',
        'total_gst_paid',
        'gst_payable',
        'adjustments',
        'status',
        'filed_at',
        'filed_by',
        'ird_period',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_sales' => 'decimal:2',
        'total_gst_collected' => 'decimal:2',
        'total_purchases' => 'decimal:2',
        'total_gst_paid' => 'decimal:2',
        'gst_payable' => 'decimal:2',
        'adjustments' => 'decimal:2',
        'filed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FinGstReturnLine::class, 'gst_return_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeFiled($query)
    {
        return $query->where('status', 'filed');
    }
}
