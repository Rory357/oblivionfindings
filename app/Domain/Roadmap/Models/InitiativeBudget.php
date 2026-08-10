<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeBudget extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_budgets';

    protected $fillable = [
        'initiative_id',
        'fiscal_year',
        'quarter',
        'currency',
        'capex_low',
        'capex_high',
        'opex_low',
        'opex_high',
        'recurring_low',
        'recurring_high',
        'planned_total',
        'forecast_total',
        'actual_total',
        'variance_total',
        'variance_reason',
        'status',
    ];

    protected $casts = [
        'capex_low' => 'decimal:2',
        'capex_high' => 'decimal:2',
        'opex_low' => 'decimal:2',
        'opex_high' => 'decimal:2',
        'recurring_low' => 'decimal:2',
        'recurring_high' => 'decimal:2',
        'planned_total' => 'decimal:2',
        'forecast_total' => 'decimal:2',
        'actual_total' => 'decimal:2',
        'variance_total' => 'decimal:2',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function recalculateVariance(): void
    {
        $planned = (float) ($this->planned_total ?? 0);
        $actual = (float) ($this->actual_total ?? 0);

        $this->variance_total = round($actual - $planned, 2);
    }
}
