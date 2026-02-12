<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Model;

class RiskHeatmapSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'heatmap_data',
        'summary',
        'by_category',
        'captured_by',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'heatmap_data' => 'array',
        'summary' => 'array',
        'by_category' => 'array',
    ];

    public static function capture(): self
    {
        $risks = RiskRegisterEntry::active()->get();

        $cells = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) {
                $matching = $risks->filter(fn($r) => $r->likelihood_score == $l && $r->impact_score == $i);
                if ($matching->isNotEmpty()) {
                    $cells[] = [
                        'likelihood' => $l,
                        'impact' => $i,
                        'count' => $matching->count(),
                        'risk_ids' => $matching->pluck('id')->toArray(),
                    ];
                }
            }
        }

        $byCategory = $risks->groupBy('category')->map(fn($group) => [
            'count' => $group->count(),
            'avg_score' => round($group->avg('residual_score'), 1),
        ])->toArray();

        return static::create([
            'snapshot_date' => now()->toDateString(),
            'heatmap_data' => ['cells' => $cells],
            'summary' => [
                'critical' => $risks->where('residual_score', '>=', 20)->count(),
                'high' => $risks->whereBetween('residual_score', [15, 19])->count(),
                'medium' => $risks->whereBetween('residual_score', [10, 14])->count(),
                'low' => $risks->where('residual_score', '<', 10)->count(),
                'above_appetite' => $risks->where('within_appetite', false)->count(),
            ],
            'by_category' => $byCategory,
            'captured_by' => auth()->id(),
        ]);
    }
}
