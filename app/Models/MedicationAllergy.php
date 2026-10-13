<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicationAllergy extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'allergen',
        'reaction',
        'notes',
        'severity',
        'identified_date',
        'identified_by',
        'recorded_by',
    ];

    protected $casts = [
        'identified_date' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Check if this is a severe allergy
     */
    public function isSevere(): bool
    {
        return in_array($this->severity, ['severe', 'life_threatening'], true);
    }

    /**
     * Get severity badge color
     */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'life_threatening' => 'red',
            'severe' => 'orange',
            'moderate' => 'yellow',
            'mild' => 'blue',
            default => 'gray',
        };
    }

    /**
     * Scope for severe allergies only
     */
    public function scopeSevere($query)
    {
        return $query->whereIn('severity', ['severe', 'life_threatening']);
    }

    /**
     * Check if a medication matches this allergy
     */
    public function matchesMedication(string $medicationName, ?\Illuminate\Support\Collection $classRules = null): bool
    {
        // EA-052 (Lane C3): the same matcher as the order check — names, the
        // built-in classes and reviewed maintained class rules.
        return \App\Services\Medication\AllergyMatching::match((string) $this->allergen, $medicationName, $classRules) !== null;
    }
}
