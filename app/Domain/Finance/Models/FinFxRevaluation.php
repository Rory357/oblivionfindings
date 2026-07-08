<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinFxRevaluation extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_fx_revaluations';

    protected $fillable = [
        'organization_id',
        'revaluation_date',
        'fiscal_period_id',
        'journal_id',
        'total_gain_loss',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'revaluation_date' => 'date',
        'total_gain_loss' => 'decimal:2',
    ];

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FinFiscalPeriod::class, 'fiscal_period_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }
}
