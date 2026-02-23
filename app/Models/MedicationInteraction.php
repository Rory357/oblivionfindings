<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'medication_a',
        'medication_b',
        'severity',
        'description',
        'clinical_effects',
        'management',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Severity levels with display info
     */
    public const SEVERITY_LEVELS = [
        'minor' => [
            'label' => 'Minor',
            'color' => 'blue',
            'description' => 'Minimally clinically significant.',
        ],
        'moderate' => [
            'label' => 'Moderate',
            'color' => 'yellow',
            'description' => 'Moderately clinically significant.',
        ],
        'major' => [
            'label' => 'Major',
            'color' => 'orange',
            'description' => 'Highly clinically significant.',
        ],
        'contraindicated' => [
            'label' => 'Contraindicated',
            'color' => 'red',
            'description' => 'Combination should be avoided.',
        ],
    ];

    /**
     * Get severity display info
     */
    public function getSeverityInfoAttribute(): array
    {
        return self::SEVERITY_LEVELS[$this->severity] ?? [
            'label' => 'Unknown',
            'color' => 'gray',
            'description' => '',
        ];
    }

    /**
     * Scope for active interactions
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope by severity
     */
    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Check if an interaction exists between two medications
     */
    public static function checkInteraction(string $medA, string $medB): ?self
    {
        return self::active()
            ->where(function ($q) use ($medA, $medB) {
                $q->where(function ($sq) use ($medA, $medB) {
                    $sq->where('medication_a', 'like', "%{$medA}%")
                        ->where('medication_b', 'like', "%{$medB}%");
                })->orWhere(function ($sq) use ($medA, $medB) {
                    $sq->where('medication_a', 'like', "%{$medB}%")
                        ->where('medication_b', 'like', "%{$medA}%");
                });
            })
            ->first();
    }

    /**
     * Find interactions for a given medication
     */
    public static function findForMedication(string $medicationName): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->where(function ($q) use ($medicationName) {
                $q->where('medication_a', 'like', "%{$medicationName}%")
                    ->orWhere('medication_b', 'like', "%{$medicationName}%");
            })
            ->orderByRaw("FIELD(severity, 'contraindicated', 'major', 'moderate', 'minor')")
            ->get();
    }

    /**
     * Check multiple medications for interactions
     */
    public static function checkMultiple(array $medications): array
    {
        $interactions = [];
        $count = count($medications);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $interaction = self::checkInteraction($medications[$i], $medications[$j]);
                if ($interaction) {
                    $interactions[] = $interaction;
                }
            }
        }

        return $interactions;
    }
}
