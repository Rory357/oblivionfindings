<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AlertSla extends Model
{
    public const ENDED_RECONCILED_NO_MATCH = 'reconciled_no_match';

    public const ENDED_DISMISSED = 'dismissed';

    public const ENDED_REOPENED = 'reopened';

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
        return $query->applicable()->where(function ($breachedQuery) {
            $this->applyAnyBreachCondition($breachedQuery);
        });
    }

    public function scopeApplicable($query)
    {
        return $query
            ->whereNotNull('sla_definition_id')
            ->where(function ($q) {
                $q->whereNull('ended_as')
                    ->orWhereNotIn('ended_as', [
                        self::ENDED_RECONCILED_NO_MATCH,
                        self::ENDED_DISMISSED,
                    ]);
            })
            ->whereHas('alert', fn ($alertQuery) => $alertQuery
                ->where('status', '!=', ControlRoomAlert::STATUS_DISMISSED));
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

    public function scopeAssessed($query)
    {
        return $query->applicable()->where(function ($assessedQuery) {
            $assessedQuery
                ->where(function ($breachedQuery) {
                    $this->applyAnyBreachCondition($breachedQuery);
                })
                ->orWhere(function ($completedQuery) {
                    $this->applyAllConfiguredMilestonesCompletedCondition($completedQuery);
                });
        });
    }

    public function scopeMet($query)
    {
        return $query->applicable()->where(function ($metQuery) {
            $metQuery->where(function ($configuredQuery) {
                foreach ($this->milestones() as $columns) {
                    $configuredQuery->orWhereNotNull($columns['deadline']);
                }
            });

            foreach ($this->milestones() as $columns) {
                $metQuery->where(function ($milestoneQuery) use ($columns) {
                    $milestoneQuery
                        ->whereNull($columns['deadline'])
                        ->orWhere(function ($configuredMilestone) use ($columns) {
                            $configuredMilestone
                                ->whereNotNull($columns['completed'])
                                ->whereColumn($columns['completed'], '<=', $columns['deadline'])
                                ->where(function ($storedFlag) use ($columns) {
                                    $storedFlag->where($columns['breached'], false)
                                        ->orWhereNull($columns['breached']);
                                });
                        });
                });
            }
        });
    }

    public function scopeMilestoneAssessed($query, string $milestone)
    {
        $columns = $this->milestoneColumns($milestone);

        return $query->applicable()
            ->whereNotNull($columns['deadline'])
            ->where(function ($assessedQuery) use ($columns) {
                $assessedQuery->whereNotNull($columns['completed'])
                    ->orWhere($columns['deadline'], '<', now());
            });
    }

    public function scopeMilestoneMet($query, string $milestone)
    {
        $columns = $this->milestoneColumns($milestone);

        return $query->applicable()
            ->whereNotNull($columns['deadline'])
            ->whereNotNull($columns['completed'])
            ->whereColumn($columns['completed'], '<=', $columns['deadline'])
            ->where(function ($storedFlag) use ($columns) {
                $storedFlag->where($columns['breached'], false)
                    ->orWhereNull($columns['breached']);
            });
    }

    public function scopeMilestoneBreached($query, string $milestone)
    {
        $columns = $this->milestoneColumns($milestone);

        return $query->applicable()->where(function ($breachedQuery) use ($columns) {
            $this->applyMilestoneBreachCondition($breachedQuery, $columns);
        });
    }

    public static function createFromDefinition(
        ControlRoomAlert $alert,
        SlaDefinition $sla,
        ?DateTimeInterface $cycleStartedAt = null,
    ): self {
        $cycleStartedAt = $cycleStartedAt === null
            ? ($alert->triggered_at ?? $alert->created_at ?? now())
            : Carbon::instance($cycleStartedAt);
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

    public function recordAcknowledge(?DateTimeInterface $timestamp = null): void
    {
        if (! $this->isApplicable() || $this->acknowledged_at !== null) {
            return;
        }

        $acknowledgedAt = $this->normaliseTimestamp($timestamp);
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

    public function recordResponse(?DateTimeInterface $timestamp = null): void
    {
        if (! $this->isApplicable() || $this->responded_at !== null) {
            return;
        }

        $respondedAt = $this->normaliseTimestamp($timestamp);
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

    public function recordResolution(?DateTimeInterface $timestamp = null): void
    {
        if (! $this->isApplicable() || $this->resolved_at !== null) {
            return;
        }

        $resolvedAt = $this->normaliseTimestamp($timestamp);
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
        if (! $this->isApplicable()) {
            return false;
        }

        foreach ($this->milestones() as $columns) {
            if ($this->milestoneIsBreached($columns)) {
                return true;
            }
        }

        return false;
    }

    public function isMilestoneBreached(string $milestone): bool
    {
        return $this->isApplicable()
            && $this->milestoneIsBreached($this->milestoneColumns($milestone));
    }

    /**
     * @return array<int, string>
     */
    public function breachTypes(): array
    {
        return collect(array_keys($this->milestones()))
            ->filter(fn (string $milestone) => $this->isMilestoneBreached($milestone))
            ->values()
            ->all();
    }

    public function effectiveFirstBreachAt(): ?Carbon
    {
        if (! $this->isBreached()) {
            return null;
        }

        $candidates = collect([$this->first_breach_at]);
        foreach ($this->milestones() as $columns) {
            if ($this->milestoneIsBreached($columns)) {
                $candidates->push($this->getAttribute($columns['deadline']));
            }
        }

        return $candidates
            ->filter()
            ->sortBy(fn (Carbon $timestamp) => $timestamp->getTimestamp())
            ->first();
    }

    public function isApplicable(): bool
    {
        return $this->sla_definition_id !== null
            && ! in_array($this->ended_as, [
                self::ENDED_RECONCILED_NO_MATCH,
                self::ENDED_DISMISSED,
            ], true)
            && $this->alert !== null
            && $this->alert->status !== ControlRoomAlert::STATUS_DISMISSED;
    }

    public function endAsDismissed(DateTimeInterface $endedAt): void
    {
        if ($this->sla_definition_id === null || in_array($this->ended_as, [
            self::ENDED_RECONCILED_NO_MATCH,
            self::ENDED_DISMISSED,
        ], true)) {
            return;
        }

        $endedAt = $this->normaliseTimestamp($endedAt);
        $history = $this->cycle_history ?? [];
        $history[] = $this->snapshotCurrentCycle(self::ENDED_DISMISSED, $endedAt);

        $this->forceFill([
            'cycle_history' => $history,
            'ended_as' => self::ENDED_DISMISSED,
        ])->save();
    }

    public function restartForReopen(
        DateTimeInterface $cycleStartedAt,
        SlaDefinition $definition,
    ): void
    {
        if ($this->ended_as === self::ENDED_DISMISSED) {
            throw new \InvalidArgumentException(
                'A dismissed SLA clock cannot be restarted as an incident response cycle.',
            );
        }

        $cycleStartedAt = $this->normaliseTimestamp($cycleStartedAt);
        $history = $this->cycle_history ?? [];
        if ($this->ended_as === null) {
            $history[] = $this->snapshotCurrentCycle(self::ENDED_REOPENED, $cycleStartedAt);
        }
        $deadlines = $definition->calculateDeadlines($cycleStartedAt);

        $this->forceFill([
            'sla_definition_id' => $definition->id,
            'acknowledge_target_minutes' => $definition->acknowledge_target_minutes,
            'response_target_minutes' => $definition->response_target_minutes,
            'resolution_target_minutes' => $definition->resolution_target_minutes,
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
            'cycle_number' => max(1, (int) $this->cycle_number) + 1,
            'cycle_started_at' => $cycleStartedAt,
            'cycle_history' => $history,
            'ended_as' => null,
        ])->save();
        $this->unsetRelation('slaDefinition');
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

        if ($this->isBreached()) {
            return 'breached';
        }

        if ($this->resolved_at) {
            return 'resolved';
        }

        $warningTime = now()->addMinutes(5);

        // Check if any deadline is within 5 minutes
        if (
            ($this->acknowledge_deadline && ! $this->acknowledged_at && $this->acknowledge_deadline->lte($warningTime)) ||
            ($this->response_deadline && ! $this->responded_at && $this->response_deadline->lte($warningTime)) ||
            ($this->resolution_deadline && ! $this->resolved_at && $this->resolution_deadline->lte($warningTime))
        ) {
            return 'at_risk';
        }

        return 'on_track';
    }

    /**
     * @return array<string, array{deadline: string, completed: string, breached: string}>
     */
    private function milestones(): array
    {
        return [
            'acknowledge' => [
                'deadline' => 'acknowledge_deadline',
                'completed' => 'acknowledged_at',
                'breached' => 'acknowledge_breached',
            ],
            'response' => [
                'deadline' => 'response_deadline',
                'completed' => 'responded_at',
                'breached' => 'response_breached',
            ],
            'resolution' => [
                'deadline' => 'resolution_deadline',
                'completed' => 'resolved_at',
                'breached' => 'resolution_breached',
            ],
        ];
    }

    /**
     * @return array{deadline: string, completed: string, breached: string}
     */
    private function milestoneColumns(string $milestone): array
    {
        $columns = $this->milestones()[$milestone] ?? null;

        if ($columns === null) {
            throw new \InvalidArgumentException("Unknown SLA milestone [{$milestone}].");
        }

        return $columns;
    }

    /**
     * @param  array{deadline: string, completed: string, breached: string}  $columns
     */
    private function applyMilestoneBreachCondition($query, array $columns): void
    {
        $query->where($columns['breached'], true)
            ->orWhere(function ($dynamicBreach) use ($columns) {
                $dynamicBreach->whereNotNull($columns['deadline'])
                    ->where(function ($timingBreach) use ($columns) {
                        $timingBreach
                            ->where(function ($overdue) use ($columns) {
                                $overdue->whereNull($columns['completed'])
                                    ->where($columns['deadline'], '<', now());
                            })
                            ->orWhere(function ($late) use ($columns) {
                                $late->whereNotNull($columns['completed'])
                                    ->whereColumn($columns['completed'], '>', $columns['deadline']);
                            });
                    });
            });
    }

    private function applyAnyBreachCondition($query): void
    {
        foreach ($this->milestones() as $index => $columns) {
            $boolean = $index === 'acknowledge' ? 'where' : 'orWhere';
            $query->{$boolean}(function ($milestoneQuery) use ($columns) {
                $this->applyMilestoneBreachCondition($milestoneQuery, $columns);
            });
        }
    }

    private function applyAllConfiguredMilestonesCompletedCondition($query): void
    {
        $query->where(function ($configuredQuery) {
            foreach ($this->milestones() as $columns) {
                $configuredQuery->orWhereNotNull($columns['deadline']);
            }
        });

        foreach ($this->milestones() as $columns) {
            $query->where(function ($milestoneQuery) use ($columns) {
                $milestoneQuery->whereNull($columns['deadline'])
                    ->orWhereNotNull($columns['completed']);
            });
        }
    }

    /**
     * @param  array{deadline: string, completed: string, breached: string}  $columns
     */
    private function milestoneIsBreached(array $columns): bool
    {
        if ((bool) $this->getAttribute($columns['breached'])) {
            return true;
        }

        $deadline = $this->getAttribute($columns['deadline']);
        if (! $deadline) {
            return false;
        }

        $completed = $this->getAttribute($columns['completed']);

        return $completed
            ? $completed->gt($deadline)
            : now()->gt($deadline);
    }

    private function normaliseTimestamp(?DateTimeInterface $timestamp): Carbon
    {
        return $timestamp === null ? now() : Carbon::instance($timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotCurrentCycle(string $endedAs, DateTimeInterface $endedAt): array
    {
        $definition = $this->slaDefinition;

        return [
            'cycle_number' => (int) $this->cycle_number,
            'cycle_started_at' => $this->cycle_started_at?->toIso8601String(),
            'ended_at' => $endedAt->format(DATE_ATOM),
            'ended_as' => $endedAs,
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
    }
}
