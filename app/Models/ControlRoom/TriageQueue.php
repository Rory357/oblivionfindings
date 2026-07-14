<?php

namespace App\Models\ControlRoom;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TriageQueue extends Model
{
    protected $table = 'control_room_triage_queues';

    protected $fillable = [
        'name',
        'code',
        'tier',
        'description',
        'handle_severities',
        'handle_sources',
        'handle_alert_types',
        'assigned_roles',
        'assigned_users',
        'escalate_to_queue_id',
        'auto_escalate_after_minutes',
        'is_active',
    ];

    protected $casts = [
        'handle_severities' => 'array',
        'handle_sources' => 'array',
        'handle_alert_types' => 'array',
        'assigned_roles' => 'array',
        'assigned_users' => 'array',
        'is_active' => 'boolean',
    ];

    public function escalateToQueue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'escalate_to_queue_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(\App\Models\ControlRoomAlert::class, 'queue_id');
    }

    public function alertHistory(): HasMany
    {
        return $this->hasMany(AlertQueue::class, 'queue_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTier($query, int $tier)
    {
        return $query->where('tier', $tier);
    }

    public static function findForAlert(string $severity, string $source, string $alertType): ?self
    {
        return static::active()
            ->orderBy('tier')
            ->get()
            ->first(function ($queue) use ($severity, $source, $alertType) {
                // Check severity match
                if ($queue->handle_severities && !in_array($severity, $queue->handle_severities)) {
                    return false;
                }

                // Check source match
                if ($queue->handle_sources && !in_array($source, $queue->handle_sources)) {
                    return false;
                }

                // Check alert type match
                if ($queue->handle_alert_types && !in_array($alertType, $queue->handle_alert_types)) {
                    return false;
                }

                return true;
            });
    }

    public function getOpenAlertCount(): int
    {
        return $this->alerts()->unresolved()->count();
    }

    public function getCriticalAlertCount(): int
    {
        return $this->alerts()->unresolved()->highPriority()->count();
    }

    public function shouldAutoEscalate(AlertQueue $assignment, ?CarbonInterface $asOf = null): bool
    {
        $dwellMinutes = (int) ($this->auto_escalate_after_minutes ?? 0);

        if (! $this->is_active
            || $dwellMinutes <= 0
            || ! $this->escalate_to_queue_id
            || $assignment->exited_at !== null
            || (int) $assignment->queue_id !== (int) $this->id) {
            return false;
        }

        $asOf ??= now();

        return $assignment->entered_at !== null
            && $assignment->entered_at->lt(
                $asOf->copy()->subMinutes($dwellMinutes),
            );
    }
}
