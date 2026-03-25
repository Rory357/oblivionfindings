<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarterlyRoadmapPlanItem extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $table = 'roadmap_quarterly_plan_items';

    protected $fillable = [
        'tenant_id',
        'quarterly_plan_id',
        'initiative_id',
        'rank',
        'planned_capex',
        'planned_opex',
        'planned_outcome',
        'decision_required',
        'decision_type',
        'decision_due_date',
        'status_at_snapshot',
        'score_at_snapshot',
        'risk_delta_at_snapshot',
        'notes',
    ];

    protected $casts = [
        'planned_capex' => 'decimal:2',
        'planned_opex' => 'decimal:2',
        'decision_required' => 'boolean',
        'decision_due_date' => 'date',
        'score_at_snapshot' => 'decimal:2',
        'risk_delta_at_snapshot' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(QuarterlyRoadmapPlan::class, 'quarterly_plan_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}
