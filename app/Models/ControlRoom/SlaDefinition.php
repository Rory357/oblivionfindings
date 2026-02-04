<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaDefinition extends Model
{
    protected $table = 'control_room_sla_definitions';

    protected $fillable = [
        'name',
        'code',
        'description',
        'alert_types',
        'severities',
        'sources',
        'acknowledge_target_minutes',
        'response_target_minutes',
        'resolution_target_minutes',
        'escalate_on_acknowledge_breach',
        'escalate_on_response_breach',
        'escalate_on_resolution_breach',
        'breach_notify_roles',
        'business_hours_only',
        'business_hours',
        'is_active',
    ];

    protected $casts = [
        'alert_types' => 'array',
        'severities' => 'array',
        'sources' => 'array',
        'breach_notify_roles' => 'array',
        'business_hours' => 'array',
        'escalate_on_acknowledge_breach' => 'boolean',
        'escalate_on_response_breach' => 'boolean',
        'escalate_on_resolution_breach' => 'boolean',
        'business_hours_only' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function alertSlas(): HasMany
    {
        return $this->hasMany(AlertSla::class, 'sla_definition_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function findForAlert(string $alertType, string $severity, string $source): ?self
    {
        return static::active()
            ->get()
            ->first(function ($sla) use ($alertType, $severity, $source) {
                // Check alert type match
                if ($sla->alert_types && !in_array($alertType, $sla->alert_types)) {
                    return false;
                }

                // Check severity match
                if ($sla->severities && !in_array($severity, $sla->severities)) {
                    return false;
                }

                // Check source match
                if ($sla->sources && !in_array($source, $sla->sources)) {
                    return false;
                }

                return true;
            });
    }

    public function isWithinBusinessHours(): bool
    {
        if (!$this->business_hours_only || !$this->business_hours) {
            return true;
        }

        $now = now();
        $currentTime = $now->format('H:i');
        $currentDay = $now->dayOfWeekIso; // 1 = Monday, 7 = Sunday

        $hours = $this->business_hours;
        $start = $hours['start'] ?? '08:00';
        $end = $hours['end'] ?? '18:00';
        $days = $hours['days'] ?? [1, 2, 3, 4, 5];

        if (!in_array($currentDay, $days)) {
            return false;
        }

        return $currentTime >= $start && $currentTime <= $end;
    }

    public function calculateDeadlines(\DateTime $triggeredAt): array
    {
        $deadlines = [];

        if ($this->acknowledge_target_minutes) {
            $deadlines['acknowledge'] = (clone $triggeredAt)->modify("+{$this->acknowledge_target_minutes} minutes");
        }

        if ($this->response_target_minutes) {
            $deadlines['response'] = (clone $triggeredAt)->modify("+{$this->response_target_minutes} minutes");
        }

        if ($this->resolution_target_minutes) {
            $deadlines['resolution'] = (clone $triggeredAt)->modify("+{$this->resolution_target_minutes} minutes");
        }

        return $deadlines;
    }
}
