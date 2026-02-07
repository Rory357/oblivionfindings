<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalRule extends Model
{
    protected $table = 'control_room_signal_rules';

    protected $fillable = [
        'name',
        'signal_type_id',
        'signal_type_code',
        'signal_source_id',
        'priority',
        'is_active',
        'conditions',
        'output_severity',
        'severity_override',
        'output_escalation_level',
        'output_tier',
        'playbook_id',
        'notify_roles',
        'notify_users',
        'deduplicate',
        'dedup_window_minutes',
        'suppress_in_maintenance',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deduplicate' => 'boolean',
        'suppress_in_maintenance' => 'boolean',
        'conditions' => 'array',
        'notify_roles' => 'array',
        'notify_users' => 'array',
    ];

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(SignalType::class, 'signal_type_id');
    }

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class, 'signal_source_id');
    }

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForSignalType($query, string $typeCode)
    {
        return $query->where(function ($q) use ($typeCode) {
            $q->where('signal_type_code', $typeCode)
                ->orWhereNull('signal_type_code');
        });
    }

    public function scopeForSource($query, ?int $sourceId)
    {
        return $query->where(function ($q) use ($sourceId) {
            $q->where('signal_source_id', $sourceId)
                ->orWhereNull('signal_source_id');
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority');
    }

    public static function findMatchingRules(Signal $signal): \Illuminate\Support\Collection
    {
        return static::active()
            ->forSignalType($signal->signal_type_code)
            ->forSource($signal->signal_source_id)
            ->ordered()
            ->get()
            ->filter(fn ($rule) => $rule->matchesConditions($signal));
    }

    public function matchesConditions(Signal $signal): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $key => $value) {
            $signalValue = $this->getSignalValue($signal, $key);

            if (is_array($value)) {
                // Array means "in" comparison
                if (!in_array($signalValue, $value)) {
                    return false;
                }
            } elseif ($signalValue !== $value) {
                return false;
            }
        }

        return true;
    }

    protected function getSignalValue(Signal $signal, string $key)
    {
        // Check direct properties
        if (isset($signal->{$key})) {
            return $signal->{$key};
        }

        // Check normalized data
        if (isset($signal->normalized_data[$key])) {
            return $signal->normalized_data[$key];
        }

        // Check payload
        if (isset($signal->payload[$key])) {
            return $signal->payload[$key];
        }

        // Special computed values
        switch ($key) {
            case 'time_of_day':
                $hour = $signal->occurred_at->hour;
                if ($hour >= 6 && $hour < 12) return 'morning';
                if ($hour >= 12 && $hour < 18) return 'afternoon';
                if ($hour >= 18 && $hour < 22) return 'evening';
                return 'night';

            case 'day_of_week':
                return strtolower($signal->occurred_at->format('l'));

            case 'is_weekend':
                return $signal->occurred_at->isWeekend();

            case 'is_business_hours':
                $hour = $signal->occurred_at->hour;
                $isWeekday = !$signal->occurred_at->isWeekend();
                return $isWeekday && $hour >= 8 && $hour < 18;
        }

        return null;
    }

    public function getOutputSeverity(Signal $signal): string
    {
        return $this->output_severity ?? $signal->severity_hint ?? 'medium';
    }

    public function getOutputEscalationLevel(): int
    {
        return $this->output_escalation_level ?? 0;
    }

    public function getOutputTier(): int
    {
        return $this->output_tier ?? 1;
    }

    public function setSeverityOverrideAttribute($value): void
    {
        $this->attributes['output_severity'] = $value;
    }
}
