<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSla extends Model
{
    public const ENDED_RECONCILED_NO_MATCH = 'reconciled_no_match';

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
        'cycle_number',
        'cycle_started_at',
        'cycle_history',
        'ended_as',
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
        'cycle_number' => 'integer',
        'cycle_started_at' => 'datetime',
        'cycle_history' => 'array',
        'ended_as' => 'string',
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
        return $query->applicable()->where(function ($q) {
            $q->where('acknowledge_breached', true)
                ->orWhere('response_breached', true)
                ->orWhere('resolution_breached', true);
        });
    }

    public function scopeApplicable($query)
    {
        return $query
            ->whereNotNull('sla_definition_id')
            ->where(function ($q) {
                $q->whereNull('ended_as')
                    ->orWhere('ended_as', '!=', self::ENDED_RECONCILED_NO_MATCH);
            });
    }

    public function scopeAtRisk($query, int $warningMinutes = 5)
    {
        $warningTime = now()->addMinutes($warningMinutes);

        return $query->applicable()->where(function ($q) use ($warningTime) {
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
        $cycleStartedAt = $alert->triggered_at ?? $alert->created_at ?? now();
        $deadlines = $sla->calculateDeadlines($cycleStartedAt);

        return static::create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $sla->id,
            'acknowledge_target_minutes' => $sla->acknowledge_target_minutes,
            'response_target_minutes' => $sla->response_target_minutes,
            'resolution_target_minutes' => $sla->resolution_target_minutes,
            'acknowledge_deadline' => $deadlines['acknowledge'] ?? null,
            'response_deadline' => $deadlines['response'] ?? null,
            'resolution_deadline' => $deadlines['resolution'] ?? null,
            'cycle_started_at' => $cycleStartedAt,
        ]);
    }

    public function recordAcknowledge(): void
    {
        if (! $this->isApplicable()) {
            return;
        }

        $acknowledgedAt = now();
        $breached = $this->acknowledge_deadline && $acknowledgedAt->gt($this->acknowledge_deadline);
        $variance = $this->acknowledge_deadline
            ? $this->acknowledge_deadline->diffInMinutes($acknowledgedAt, false)
            : null;

        $this->update([
            'acknowledged_at' => $acknowledgedAt,
            'acknowledge_breached' => $breached,
            'acknowledge_variance_minutes' => $variance,
            'first_breach_at' => $breached && ! $this->first_breach_at ? $acknowledgedAt : $this->first_breach_at,
        ]);
    }

    public function recordResponse(): void
    {
        if (! $this->isApplicable()) {
            return;
        }

        $respondedAt = now();
        $breached = $this->response_deadline && $respondedAt->gt($this->response_deadline);
        $variance = $this->response_deadline
            ? $this->response_deadline->diffInMinutes($respondedAt, false)
            : null;

        $this->update([
            'responded_at' => $respondedAt,
            'response_breached' => $breached,
            'response_variance_minutes' => $variance,
            'first_breach_at' => $breached && ! $this->first_breach_at ? $respondedAt : $this->first_breach_at,
        ]);
    }

    public function recordResolution(): void
    {
        if (! $this->isApplicable()) {
            return;
        }

        $resolvedAt = now();
        $breached = $this->resolution_deadline && $resolvedAt->gt($this->resolution_deadline);
        $variance = $this->resolution_deadline
            ? $this->resolution_deadline->diffInMinutes($resolvedAt, false)
            : null;

        $this->update([
            'resolved_at' => $resolvedAt,
            'resolution_breached' => $breached,
            'resolution_variance_minutes' => $variance,
            'first_breach_at' => $breached && ! $this->first_breach_at ? $resolvedAt : $this->first_breach_at,
        ]);
    }

    public function checkForBreaches(): array
    {
        if (! $this->isApplicable()) {
            return [];
        }

        $breaches = [];
        $now = now();

        if (! $this->acknowledged_at && $this->acknowledge_deadline && $now->gt($this->acknowledge_deadline) && ! $this->acknowledge_breached) {
            $this->update([
                'acknowledge_breached' => true,
                'first_breach_at' => $this->first_breach_at ?? $now,
            ]);
            $breaches[] = 'acknowledge';
        }

        if (! $this->responded_at && $this->response_deadline && $now->gt($this->response_deadline) && ! $this->response_breached) {
            $this->update([
                'response_breached' => true,
                'first_breach_at' => $this->first_breach_at ?? $now,
            ]);
            $breaches[] = 'response';
        }

        if (! $this->resolved_at && $this->resolution_deadline && $now->gt($this->resolution_deadline) && ! $this->resolution_breached) {
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
        return $this->isApplicable()
            && ($this->acknowledge_breached || $this->response_breached || $this->resolution_breached);
    }

    public function isApplicable(): bool
    {
        return $this->sla_definition_id !== null
            && $this->ended_as !== self::ENDED_RECONCILED_NO_MATCH;
    }

    public function terminaliseForNoMatchingDefinition(string $severity): void
    {
        if (! $this->isApplicable()) {
            return;
        }

        $endedAt = now();
        $definition = $this->slaDefinition;
        $history = $this->cycle_history ?? [];
        $history[] = [
            'cycle_number' => (int) $this->cycle_number,
            'cycle_started_at' => $this->cycle_started_at?->toIso8601String(),
            'ended_at' => $endedAt->toIso8601String(),
            'ended_as' => self::ENDED_RECONCILED_NO_MATCH,
            'reconciled_for_severity' => $severity,
            'definition' => [
                'id' => $this->sla_definition_id,
                'code' => $definition?->code,
                'name' => $definition?->name,
            ],
            'targets' => [
                'acknowledge_minutes' => $this->acknowledge_target_minutes,
                'response_minutes' => $this->response_target_minutes,
                'resolution_minutes' => $this->resolution_target_minutes,
            ],
            'deadlines' => [
                'acknowledge_at' => $this->acknowledge_deadline?->toIso8601String(),
                'response_at' => $this->response_deadline?->toIso8601String(),
                'resolution_at' => $this->resolution_deadline?->toIso8601String(),
            ],
            'results' => [
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
                'responded_at' => $this->responded_at?->toIso8601String(),
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'acknowledge_variance_minutes' => $this->acknowledge_variance_minutes,
                'response_variance_minutes' => $this->response_variance_minutes,
                'resolution_variance_minutes' => $this->resolution_variance_minutes,
                'acknowledge_breached' => (bool) $this->acknowledge_breached,
                'response_breached' => (bool) $this->response_breached,
                'resolution_breached' => (bool) $this->resolution_breached,
                'first_breach_at' => $this->first_breach_at?->toIso8601String(),
            ],
        ];

        $this->forceFill([
            'sla_definition_id' => null,
            'acknowledge_target_minutes' => null,
            'response_target_minutes' => null,
            'resolution_target_minutes' => null,
            'acknowledge_deadline' => null,
            'response_deadline' => null,
            'resolution_deadline' => null,
            'acknowledged_at' => null,
            'responded_at' => null,
            'resolved_at' => null,
            'acknowledge_variance_minutes' => null,
            'response_variance_minutes' => null,
            'resolution_variance_minutes' => null,
            'acknowledge_breached' => false,
            'response_breached' => false,
            'resolution_breached' => false,
            'first_breach_at' => null,
            'cycle_started_at' => null,
            'cycle_history' => $history,
            'ended_as' => self::ENDED_RECONCILED_NO_MATCH,
        ])->save();
    }

    public function reactivateFromDefinition(SlaDefinition $sla, \DateTime $cycleStartedAt): void
    {
        if ($this->ended_as !== self::ENDED_RECONCILED_NO_MATCH) {
            return;
        }

        $deadlines = $sla->calculateDeadlines($cycleStartedAt);
        $this->forceFill([
            'sla_definition_id' => $sla->id,
            'acknowledge_target_minutes' => $sla->acknowledge_target_minutes,
            'response_target_minutes' => $sla->response_target_minutes,
            'resolution_target_minutes' => $sla->resolution_target_minutes,
            'acknowledge_deadline' => $deadlines['acknowledge'] ?? null,
            'response_deadline' => $deadlines['response'] ?? null,
            'resolution_deadline' => $deadlines['resolution'] ?? null,
            'acknowledged_at' => null,
            'responded_at' => null,
            'resolved_at' => null,
            'acknowledge_variance_minutes' => null,
            'response_variance_minutes' => null,
            'resolution_variance_minutes' => null,
            'acknowledge_breached' => false,
            'response_breached' => false,
            'resolution_breached' => false,
            'first_breach_at' => null,
            'cycle_number' => (int) $this->cycle_number + 1,
            'cycle_started_at' => $cycleStartedAt,
            'ended_as' => null,
        ])->save();
        $this->unsetRelation('slaDefinition');
    }

    public function getStatus(): string
    {
        if (! $this->isApplicable()) {
            return 'not_applicable';
        }

        if ($this->resolved_at) {
            return 'resolved';
        }

        if ($this->isBreached()) {
            return 'breached';
        }

        $now = now();

        // Check if any deadline is within 5 minutes
        if (
            ($this->acknowledge_deadline && ! $this->acknowledged_at && $this->acknowledge_deadline->lte($now->addMinutes(5))) ||
            ($this->response_deadline && ! $this->responded_at && $this->response_deadline->lte($now->addMinutes(5))) ||
            ($this->resolution_deadline && ! $this->resolved_at && $this->resolution_deadline->lte($now->addMinutes(5)))
        ) {
            return 'at_risk';
        }

        return 'on_track';
    }
}
