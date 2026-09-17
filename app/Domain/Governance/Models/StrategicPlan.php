<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StrategicPlan extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    /** Resolution statuses in which a passed vote is final (matches budgets). */
    public const PASSED_RESOLUTION_STATUSES = ['closed', 'implemented', 'archived'];

    protected $fillable = [
        'title',
        'planning_horizon',
        'period_start',
        'period_end',
        'vision_statement',
        'mission_statement',
        'values',
        'status',
        'approval_resolution_id',
        'approved_by_board_at',
        'version_number',
        'version_notes',
        'supersedes_plan_id',
        'last_snapshot',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_by_board_at' => 'datetime',
        'values' => 'array',
        'version_number' => 'integer',
        'last_snapshot' => 'array',
    ];

    public function setStatusAttribute($value): void
    {
        $map = [
            'active' => 'approved',
            'completed' => 'archived',
        ];
        $this->attributes['status'] = $map[$value] ?? $value;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(StrategicPlan::class, 'supersedes_plan_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(StrategicGoal::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['approved', 'active']);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDraft($query)
    {
        return $query->whereIn('status', ['draft', 'review']);
    }

    public function scopeSuperseded($query)
    {
        return $query->where('status', 'superseded');
    }

    public function scopeArchived($query)
    {
        return $query->whereIn('status', ['archived', 'completed']);
    }

    public function isDraft(): bool
    {
        return in_array($this->status, ['draft', 'review']);
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'active']);
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }

    public function isArchived(): bool
    {
        return in_array($this->status, ['archived', 'completed']);
    }

    /**
     * Approve this plan under a carried resolution.
     *
     * Authority comes only from an explicit binding created while the paper
     * was a draft, naming this exact plan id and revision (version plus a
     * fingerprint of its content). Titles and motion wording never confer
     * authority. The binding is verified under row locks and consumed once.
     */
    public function approve(int $resolutionId, ?int $actorId = null): void
    {
        $authority = app(GovernanceResolutionAuthorityService::class);

        DB::transaction(function () use ($resolutionId, $actorId, $authority) {
            $lockedPlan = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $resolution = Resolution::query()->whereKey($resolutionId)->lockForUpdate()->first();

            if (! $resolution) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'That resolution no longer exists.',
                ]);
            }

            if (! in_array($resolution->status, self::PASSED_RESOLUTION_STATUSES, true) || $resolution->outcome !== 'carried') {
                throw ValidationException::withMessages([
                    'resolution_id' => "This resolution can't be used yet — voting must be finished and the result recorded as passed.",
                ]);
            }

            if (! $lockedPlan->isDraft()) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'Only a draft strategic plan can be approved. Create a new version to change an approved plan.',
                ]);
            }

            $alreadyUsed = static::query()
                ->where('approval_resolution_id', $resolutionId)
                ->where('id', '!=', $lockedPlan->id)
                ->whereIn('status', ['approved', 'active'])
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'This resolution has already been used to approve another strategic plan.',
                ]);
            }

            try {
                $authority->verifyAndConsume(
                    $resolution,
                    GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN,
                    (int) $lockedPlan->getKey(),
                    $authority->strategicPlanTerms($lockedPlan),
                    $actorId ?? auth()->id(),
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    'resolution_id' => $exception->getMessage(),
                ]);
            }

            $lockedPlan->update([
                'status' => 'approved',
                'approval_resolution_id' => $resolutionId,
                'approved_by_board_at' => now(),
            ]);

            $lockedPlan->captureSnapshot();

            if ($lockedPlan->supersedes_plan_id) {
                $superseded = static::query()->whereKey($lockedPlan->supersedes_plan_id)->lockForUpdate()->first();
                if ($superseded && in_array($superseded->status, ['approved', 'active'])) {
                    $superseded->update(['status' => 'superseded']);
                }
            }
        }, 3);

        $this->refresh();
    }

    public function archive(): void
    {
        $this->update(['status' => 'archived']);
    }

    public function getProgressPercentage(): float
    {
        $goals = $this->goals;
        if ($goals->isEmpty()) {
            return 0;
        }
        return round($goals->avg('progress_pct'), 1);
    }

    public function createNewVersion(string $notes, int $userId): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($notes, $userId) {
            $newPlan = $this->replicate([
                'approval_resolution_id',
                'approved_by_board_at',
                'last_snapshot',
            ]);
            $newPlan->fill([
                'version_number' => $this->version_number + 1,
                'version_notes' => $notes,
                'supersedes_plan_id' => $this->id,
                'status' => 'draft',
                'created_by' => $userId,
            ]);
            $newPlan->save();

            // Copy goals preserving origin lineage
            foreach ($this->goals as $goal) {
                $newGoal = $goal->replicate();
                $newGoal->strategic_plan_id = $newPlan->id;
                $newGoal->origin_goal_id = $goal->origin_goal_id ?? $goal->id;
                $newGoal->save();

                foreach ($goal->initiatives as $initiative) {
                    $newInitiative = $initiative->replicate();
                    $newInitiative->strategic_goal_id = $newGoal->id;
                    $newInitiative->save();
                }
            }

            return $newPlan;
        });
    }

    /**
     * Capture a snapshot of current goals/progress for "what changed" comparison.
     */
    public function captureSnapshot(): void
    {
        $snapshot = $this->goals()->get()->map(fn ($g) => [
            'id' => $g->id,
            'origin_goal_id' => $g->origin_goal_id ?? $g->id,
            'title' => $g->title,
            'pillar' => $g->pillar ?? null,
            'progress_pct' => (float) $g->progress_pct,
            'status' => $g->status ?? null,
            'lead_executive_id' => $g->lead_executive_id,
            'timeframe' => $g->timeframe,
        ])->toArray();

        $this->update(['last_snapshot' => $snapshot]);
    }

    /**
     * What changed compared with the version the board approved.
     *
     * Goals are compared with this plan's own saved copy from approval or,
     * for a new draft version, with the version it copies. For a new version
     * the vision, mission and values are compared with that version too.
     * Every change is described in plain words (labels, not stored values).
     *
     * @return array{has_snapshot: bool, baseline_label: string, compared_version: int|null, changes: array<int, array{type: string, area: string, goal: string, detail: string}>}
     */
    public function getChangesSinceLastSnapshot(): array
    {
        $baseline = null;
        $baselineLabel = 'Comparison not available';
        $comparedVersion = null;
        $prior = null;

        if (! empty($this->last_snapshot)) {
            $baseline = $this->last_snapshot;
            $baselineLabel = 'the version the board approved';
            $comparedVersion = (int) $this->version_number;
        } elseif ($this->supersedes_plan_id && $this->supersedes) {
            $prior = $this->supersedes;
            if (! empty($prior->last_snapshot)) {
                $baseline = $prior->last_snapshot;
                $baselineLabel = "version {$prior->version_number}, which the board approved";
            } elseif ($prior->goals()->exists()) {
                $baseline = $prior->goals()->get()->map(fn ($g) => [
                    'id' => $g->id,
                    'origin_goal_id' => $g->origin_goal_id ?? $g->id,
                    'title' => $g->title,
                    'pillar' => $g->pillar ?? null,
                    'progress_pct' => (float) $g->progress_pct,
                    'status' => $g->status ?? null,
                ])->toArray();
                $baselineLabel = "version {$prior->version_number}";
            } else {
                $baseline = [];
                $baselineLabel = "version {$prior->version_number}";
            }
            $comparedVersion = (int) $prior->version_number;
        }

        if ($baseline === null || ($baseline === [] && $prior === null)) {
            return [
                'has_snapshot' => false,
                'baseline_label' => 'Comparison not available',
                'compared_version' => null,
                'changes' => [],
            ];
        }

        $changes = $prior ? $this->directionChanges($prior) : [];

        $previousByLineage = [];
        $previousById = [];
        $previousByTitle = [];

        foreach ($baseline as $item) {
            $lineageKey = $item['origin_goal_id'] ?? $item['id'];
            $previousByLineage[$lineageKey] = $item;
            $previousById[$item['id']] = $item;
            $previousByTitle[trim($item['title'])] = $item;
        }

        $currentGoals = $this->goals()->get();
        $matchedBaselineLineageKeys = [];

        foreach ($currentGoals as $goal) {
            $goalLineageKey = $goal->origin_goal_id ?? $goal->id;

            $old = null;
            $matchedKey = null;

            if (isset($previousByLineage[$goalLineageKey])) {
                $old = $previousByLineage[$goalLineageKey];
                $matchedKey = $old['origin_goal_id'] ?? $old['id'];
            } elseif (isset($previousById[$goal->id])) {
                $old = $previousById[$goal->id];
                $matchedKey = $old['origin_goal_id'] ?? $old['id'];
            } elseif (isset($previousByTitle[trim($goal->title)])) {
                $old = $previousByTitle[trim($goal->title)];
                $matchedKey = $old['origin_goal_id'] ?? $old['id'];
            }

            if (! $old) {
                $changes[] = [
                    'type' => 'added',
                    'area' => 'goal',
                    'goal' => $goal->title,
                    'detail' => 'New goal added.',
                ];

                continue;
            }

            $matchedBaselineLineageKeys[$matchedKey] = true;

            $diffs = [];
            $oldProgress = (float) ($old['progress_pct'] ?? 0);
            $newProgress = (float) $goal->progress_pct;
            if (abs($oldProgress - $newProgress) >= 0.01) {
                $diffs[] = sprintf('Progress %s%% → %s%%', self::percent($oldProgress), self::percent($newProgress));
            }

            $oldStatus = $old['status'] ?? 'not_started';
            $newStatus = $goal->status ?? 'not_started';
            if ($oldStatus !== $newStatus) {
                $diffs[] = sprintf(
                    '%s → %s',
                    GovernanceLabels::label('goal_status', $oldStatus),
                    GovernanceLabels::label('goal_status', $newStatus),
                );
            }

            $oldPillar = $old['pillar'] ?? null;
            $newPillar = $goal->pillar ?? null;
            if ($oldPillar !== $newPillar && $oldPillar && $newPillar) {
                $diffs[] = sprintf(
                    'Theme %s → %s',
                    GovernanceLabels::label('theme', $oldPillar),
                    GovernanceLabels::label('theme', $newPillar),
                );
            }

            if ($old['title'] !== $goal->title) {
                $diffs[] = "Renamed from \"{$old['title']}\"";
            }

            if (! empty($diffs)) {
                $changes[] = [
                    'type' => 'updated',
                    'area' => 'goal',
                    'goal' => $goal->title,
                    'detail' => implode(' · ', $diffs),
                ];
            }
        }

        foreach ($baseline as $item) {
            $lineageKey = $item['origin_goal_id'] ?? $item['id'];
            if (! isset($matchedBaselineLineageKeys[$lineageKey])) {
                $changes[] = [
                    'type' => 'removed',
                    'area' => 'goal',
                    'goal' => $item['title'],
                    'detail' => 'Removed from this version.',
                ];
            }
        }

        return [
            'has_snapshot' => true,
            'baseline_label' => $baselineLabel,
            'compared_version' => $comparedVersion,
            'changes' => $changes,
        ];
    }

    /**
     * Vision, mission and values compared with the version this one copies.
     *
     * @return array<int, array{type: string, area: string, goal: string, detail: string}>
     */
    private function directionChanges(self $prior): array
    {
        $changes = [];

        foreach (['vision_statement' => 'Vision', 'mission_statement' => 'Mission'] as $field => $label) {
            $before = self::statement($prior->{$field});
            $after = self::statement($this->{$field});

            if ($before === $after) {
                continue;
            }

            $changes[] = match (true) {
                $before === null => ['type' => 'added', 'area' => 'direction', 'goal' => $label, 'detail' => "{$label} written for this version."],
                $after === null => ['type' => 'removed', 'area' => 'direction', 'goal' => $label, 'detail' => "{$label} removed from this version."],
                default => ['type' => 'updated', 'area' => 'direction', 'goal' => $label, 'detail' => "{$label} rewritten."],
            };
        }

        $beforeValues = self::valueNames($prior->values);
        $afterValues = self::valueNames($this->values);
        $addedValues = array_values(array_diff($afterValues, $beforeValues));
        $removedValues = array_values(array_diff($beforeValues, $afterValues));

        if ($addedValues !== [] || $removedValues !== []) {
            $parts = [];
            if ($addedValues !== []) {
                $parts[] = 'Added '.implode(', ', $addedValues);
            }
            if ($removedValues !== []) {
                $parts[] = 'Removed '.implode(', ', $removedValues);
            }

            $changes[] = ['type' => 'updated', 'area' => 'direction', 'goal' => 'Values', 'detail' => implode(' · ', $parts).'.'];
        }

        return $changes;
    }

    /** Legacy plans stored "TBD" for a statement nobody had written. */
    public static function statement(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' || strcasecmp($text, 'TBD') === 0 ? null : $text;
    }

    /** @return array<int, string> */
    private static function valueNames(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($entry) => trim((string) (is_array($entry) ? ($entry['value'] ?? '') : $entry)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
