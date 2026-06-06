<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteDailyNote extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'stay_id',
        'client_id',
        'note_date',
        'shift_period',
        'mood',
        'appetite',
        'sleep_quality',
        'engagement',
        'taha_wairua',
        'taha_whanau',
        'whanau_contact',
        'cultural_support_provided',
        'mobility',
        'activities',
        'observations',
        'concerns',
        'goals_progress',
        'medication_notes',
        'personal_care_notes',
        'nutrition_notes',
        'incident_occurred',
        'linked_incident_id',
        'sensitive_flag',
        'attachments',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'note_date' => 'date',
        'incident_occurred' => 'boolean',
        'sensitive_flag' => 'boolean',
        'attachments' => 'array',
    ];

    // Shift periods
    public const SHIFT_MORNING = 'morning';

    public const SHIFT_AFTERNOON = 'afternoon';

    public const SHIFT_EVENING = 'evening';

    public const SHIFT_NIGHT = 'night';

    public const SHIFT_ALL_DAY = 'all_day';

    // Wellbeing levels
    public const LEVEL_VERY_LOW = 'very_low';

    public const LEVEL_LOW = 'low';

    public const LEVEL_NEUTRAL = 'neutral';

    public const LEVEL_GOOD = 'good';

    public const LEVEL_EXCELLENT = 'excellent';

    // Mobility levels
    public const MOBILITY_BEDBOUND = 'bedbound';

    public const MOBILITY_LIMITED = 'limited';

    public const MOBILITY_ASSISTED = 'assisted';

    public const MOBILITY_INDEPENDENT = 'independent';

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function linkedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'linked_incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeForStay($query, int $stayId)
    {
        return $query->where('stay_id', $stayId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('note_date', $date);
    }

    public function scopeForDateRange($query, $start, $end)
    {
        return $query->whereBetween('note_date', [$start, $end]);
    }

    public function scopeWithConcerns($query)
    {
        return $query->whereNotNull('concerns')->where('concerns', '!=', '');
    }

    public function scopeWithIncidents($query)
    {
        return $query->where('incident_occurred', true);
    }

    public function scopeSensitive($query)
    {
        return $query->where('sensitive_flag', true);
    }

    public function scopeNonSensitive($query)
    {
        return $query->where('sensitive_flag', false);
    }

    // Helper methods
    public function hasConcerns(): bool
    {
        return ! empty($this->concerns);
    }

    public function hasIncident(): bool
    {
        return $this->incident_occurred;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive_flag;
    }

    public function getWellbeingScore(): ?int
    {
        $levels = [
            'very_low' => 1, 'very_poor' => 1,
            'low' => 2, 'poor' => 2, 'none' => 1, 'minimal' => 2,
            'neutral' => 3, 'fair' => 3, 'moderate' => 3,
            'good' => 4,
            'excellent' => 5,
        ];

        $scores = [];

        if ($this->mood) {
            $scores[] = $levels[$this->mood] ?? 3;
        }
        if ($this->appetite) {
            $scores[] = $levels[$this->appetite] ?? 3;
        }
        if ($this->sleep_quality) {
            $scores[] = $levels[$this->sleep_quality] ?? 3;
        }
        if ($this->engagement) {
            $scores[] = $levels[$this->engagement] ?? 3;
        }
        if ($this->taha_wairua) {
            $scores[] = $levels[$this->taha_wairua] ?? 3;
        }
        if ($this->taha_whanau) {
            $scores[] = $levels[$this->taha_whanau] ?? 3;
        }

        if (empty($scores)) {
            return null;
        }

        return (int) round(array_sum($scores) / count($scores));
    }

    public function getWellbeingSummary(): string
    {
        $score = $this->getWellbeingScore();

        if ($score === null) {
            return 'Not assessed';
        }

        return match (true) {
            $score <= 1 => 'Very low wellbeing - attention needed',
            $score <= 2 => 'Low wellbeing - monitor closely',
            $score <= 3 => 'Moderate wellbeing',
            $score <= 4 => 'Good wellbeing',
            default => 'Excellent wellbeing',
        };
    }
}
