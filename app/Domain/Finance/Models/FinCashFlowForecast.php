<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinCashFlowForecast extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Finance\FinCashFlowForecastFactory::new();
    }

    protected $table = 'fin_cash_flow_forecasts';

    protected $fillable = [
        'organization_id',
        'name',
        'forecast_date',
        'period_start',
        'period_end',
        'period_type',
        'opening_balance',
        'forecast_data',
        'assumptions',
        'status',
        'created_by',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance' => 'decimal:2',
        'forecast_data' => 'array',
        'assumptions' => 'array',
    ];

    public function scenarios(): HasMany
    {
        return $this->hasMany(FinCashFlowScenario::class, 'forecast_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where('organization_id', $orgId));
    }
}
