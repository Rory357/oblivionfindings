<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceKpi extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'performance_kpis';

    protected $fillable = [
        'performance_review_id',
        'pillar',
        'kpi_name',
        'kpi_definition',
        'data_source',
        'calculation_method',
        'target_value',
        'actual_value',
        'unit',
        'period_start',
        'period_end',
        'is_automated',
        'last_synced_at',
        'sync_notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'last_synced_at' => 'datetime',
        'is_automated' => 'boolean',
    ];

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class);
    }

    public function scopeByPillar($query, string $pillar)
    {
        return $query->where('pillar', $pillar);
    }

    public function scopeAutomated($query)
    {
        return $query->where('is_automated', true);
    }

    public function scopeNeedsSync($query)
    {
        return $query->where('is_automated', true)
            ->where(function ($q) {
                $q->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', now()->subDay());
            });
    }

    public function syncFromDataSource(): void
    {
        if (!$this->is_automated) {
            return;
        }

        // This would be implemented based on data_source mapping
        // to pull actual values from operational systems
        $value = $this->fetchValueFromSource();
        
        $this->update([
            'actual_value' => $value,
            'last_synced_at' => now(),
        ]);
    }

    protected function fetchValueFromSource(): mixed
    {
        // Implementation depends on data_source
        // Examples: incidents.count, control_room.mttr, etc.
        return null;
    }

    public function getVariance(): ?float
    {
        if (!is_numeric($this->actual_value) || !is_numeric($this->target_value)) {
            return null;
        }
        return $this->actual_value - $this->target_value;
    }

    public function getVariancePercentage(): ?float
    {
        if (!$this->target_value || $this->target_value == 0) {
            return null;
        }
        return (($this->actual_value - $this->target_value) / $this->target_value) * 100;
    }
}
