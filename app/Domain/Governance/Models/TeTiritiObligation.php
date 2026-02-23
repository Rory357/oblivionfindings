<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeTiritiObligation extends Model
{
    protected $fillable = [
        'principle', 'title', 'description', 'status', 'evidence',
        'actions_taken', 'target_date', 'progress_pct', 'owner_id',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];

    public const PRINCIPLES = [
        'partnership' => 'Partnership (Rangatiratanga)',
        'participation' => 'Participation (Whakauru)',
        'protection' => 'Protection (Whakamarumaru)',
        'equity' => 'Equity (Ōritetanga)',
        'options' => 'Options (Kōwhiringa)',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeByPrinciple($query, string $principle)
    {
        return $query->where('principle', $principle);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function getPrincipleLabel(): string
    {
        return self::PRINCIPLES[$this->principle] ?? $this->principle;
    }

    public static function getProgressByPrinciple(): array
    {
        $obligations = static::all();
        $result = [];

        foreach (self::PRINCIPLES as $key => $label) {
            $group = $obligations->where('principle', $key);
            $result[$key] = [
                'label' => $label,
                'total' => $group->count(),
                'avg_progress' => $group->avg('progress_pct') ?? 0,
                'achieved' => $group->where('status', 'achieved')->count(),
            ];
        }

        return $result;
    }
}
