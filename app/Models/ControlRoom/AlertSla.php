<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSla extends Model
{
    protected $table = 'control_room_alert_sla';

    protected $fillable = [
        'alert_id',
        'sla_definition_id',
        'acknowledge_target_minutes',
        'response_target_minutes',
        'resolution_target_minutes',
        'acknowledge_deadline',
        'response_deadline',
        'resolution_deadline',
        'acknowledged_at',
        'responded_at',
        'resolved_at',
        'acknowledge_variance_minutes',
        'response_variance_minutes',
        'resolution_variance_minutes',
        'acknowledge_breached',
        'response_breached',
        'resolution_breached',
        'first_breach_at',
    ];

    protected $casts = [
        'acknowledge_deadline' => 'datetime',
        'response_deadline' => 'datetime',
        'resolution_deadline' => 'datetime',
        'acknowledged_at' => 'datetime',
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'first_breach_at' => 'datetime',
        'acknowledge_breached' => 'boolean',
        'response_breached' => 'boolean',
        'resolution_breached' => 'boolean',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function slaDefinition(): BelongsTo
    {
        return $this->belongsTo(SlaDefinition::class, 'sla_definition_id');
    }

    public function scopeBreached($query)
    {
        return $query->where(function ($q) {
            $q->where('acknowledge_breached', true)
                ->orWhere('response_breached', true)
                ->orWhere('resolution_breached', true);
        });
    }

    public function scopeAtRisk($query, int $warningMinutes = 5)
    {
        $warningTime = now()->addMinutes($warningMinutes);

        return $query->where(function ($q) use ($warningTime) {
            $q->where(function ($sq) use ($warningTime) {
                $sq->whereNull('acknowledged_at')
                    ->where('acknowledge_deadline', '<=', $warningTime);
            })->orWhere(function ($sq) use ($warningTime) {
                $sq->whereNull('responded_at')
                    ->where('response_deadline', '<=', $warningTime);
            })->orWhere(function ($sq) use ($warningTime) {
                $sq->whereNull('resolved_at')
                    ->where('resolution_deadline', '<=', $warningTime);
            });
        });
    }

    public static function createFromDefinition(ControlRoomAlert $alert, SlaDefinition $sla): self
    {
        $deadlines = $sla->calculateDeadlines($alert->triggered_at);

        return static::create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $sla->id,
            'acknowledge_target_minutes' => $sla->acknowledge_target_minutes,
            'response_target_minutes' => $sla->response_target_minutes,
            'resolution_target_minutes' => $sla->resolution_target_minutes,
            'acknowledge_deadline' => $deadlines['acknowledge'] ?? null,
            'response_deadline' => $deadlines['response'] ?? null,
            'resolution_deadline' => $deadlines['resolution'] ?? null,
        ]);
    }

    public function recordAcknowledge(): void
    {
        $acknowledgedAt = now();
        $breached = $this->acknowledge_deadline && $acknowledgedAt->gt($this->acknowledge_deadline);
        $variance = $this->acknowledge_deadline
            ? $this->acknowledge_deadline->diffInMinutes($acknowledgedAt, false)
            : null;

        $this->update([
            'acknowledged_at' => $acknowledgedAt,
            'acknowledge_breached' => $breached,
            'acknowledge_variance_minutes' => $variance,
            'first_breach_at' => $breached && !$this->first_breach_at ? $acknowledgedAt : $this->first_breach_at,
        ]);
    }

    public function recordResponse(): void
    {
        $respondedAt = now();
        $breached = $this->response_deadline && $respondedAt->gt($this->response_deadline);
        $variance = $this->response_deadline
            ? $this->response_deadline->diffInMinutes($respondedAt, false)
            : null;

        $this->update([
            'responded_at' => $respondedAt,
            'response_breached' => $breached,
            'response_variance_minutes' => $variance,
            'first_breach_at' => $breached && !$this->first_breach_at ? $respondedAt : $this->first_breach_at,
        ]);
    }

    public function recordResolution(): void
    {
        $resolvedAt = now();
        $breached = $this->resolution_deadline && $resolvedAt->gt($this->resolution_deadline);
        $variance = $this->resolution_deadline
            ? $this->resolution_deadline->diffInMinutes($resolvedAt, false)
            : null;

        $this->update([
            'resolved_at' => $resolvedAt,
            'resolution_breached' => $breached,
            'resolution_variance_minutes' => $variance,
            'first_breach_at' => $breached && !$this->first_breach_at ? $resolvedAt : $this->first_breach_at,
        ]);
    }

    public function checkForBreaches(): array
    {
        $breaches = [];
        $now = now();

        if (!$this->acknowledged_at && $this->acknowledge_deadline && $now->gt($this->acknowledge_deadline) && !$this->acknowledge_breached) {
            $this->update([
                'acknowledge_breached' => true,
                'first_breach_at' => $this->first_breach_at ?? $now,
            ]);
            $breaches[] = 'acknowledge';
        }

        if (!$this->responded_at && $this->response_deadline && $now->gt($this->response_deadline) && !$this->response_breached) {
            $this->update([
                'response_breached' => true,
                'first_breach_at' => $this->first_breach_at ?? $now,
            ]);
            $breaches[] = 'response';
        }

        if (!$this->resolved_at && $this->resolution_deadline && $now->gt($this->resolution_deadline) && !$this->resolution_breached) {
            $this->update([
                'resolution_breached' => true,
                'first_breach_at' => $this->first_breach_at ?? $now,
            ]);
            $breaches[] = 'resolution';
        }

        return $breaches;
    }

    public function isBreached(): bool
    {
        return $this->acknowledge_breached || $this->response_breached || $this->resolution_breached;
    }

    public function getStatus(): string
    {
        if ($this->resolved_at) {
            return 'resolved';
        }

        if ($this->isBreached()) {
            return 'breached';
        }

        $now = now();

        // Check if any deadline is within 5 minutes
        if (
            ($this->acknowledge_deadline && !$this->acknowledged_at && $this->acknowledge_deadline->lte($now->addMinutes(5))) ||
            ($this->response_deadline && !$this->responded_at && $this->response_deadline->lte($now->addMinutes(5))) ||
            ($this->resolution_deadline && !$this->resolved_at && $this->resolution_deadline->lte($now->addMinutes(5)))
        ) {
            return 'at_risk';
        }

        return 'on_track';
    }
}
