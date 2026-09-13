<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
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
                    'resolution_id' => 'The selected resolution does not exist.',
                ]);
            }

            if ($resolution->status !== 'closed' || $resolution->outcome !== 'carried') {
                throw ValidationException::withMessages([
                    'resolution_id' => 'Only a closed resolution with a carried outcome can approve a strategic plan.',
                ]);
            }

            $alreadyUsed = static::query()
                ->where('approval_resolution_id', $resolutionId)
                ->where('id', '!=', $lockedPlan->id)
                ->whereIn('status', ['approved', 'active'])
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution has already been applied to another active strategic plan.',
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
                    'resolution_id' => 'The selected resolution does not authorize approval of this strategic plan. '.$exception->getMessage(),
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
     * Get what changed since the last captured snapshot.
     */
    public function getChangesSinceLastSnapshot(): array
    {
        $baseline = null;
        $baselineLabel = 'Comparison not available';

        if (! empty($this->last_snapshot)) {
            $baseline = $this->last_snapshot;
            $baselineLabel = "Version {$this->version_number} snapshot";
        } elseif ($this->supersedes_plan_id && $this->supersedes) {
            $prior = $this->supersedes;
            if (! empty($prior->last_snapshot)) {
                $baseline = $prior->last_snapshot;
                $baselineLabel = "Version {$prior->version_number} approved baseline";
            } elseif ($prior->goals()->exists()) {
                $baseline = $prior->goals()->get()->map(fn ($g) => [
                    'id' => $g->id,
                    'origin_goal_id' => $g->origin_goal_id ?? $g->id,
                    'title' => $g->title,
                    'pillar' => $g->pillar ?? null,
                    'progress_pct' => (float) $g->progress_pct,
                    'status' => $g->status ?? null,
                ])->toArray();
                $baselineLabel = "Version {$prior->version_number} baseline";
            }
        }

        if (empty($baseline)) {
            return [
                'has_snapshot' => false,
                'baseline_label' => 'Comparison not available',
                'changes' => [],
            ];
        }

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
        $changes = [];
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
                    'goal' => $goal->title,
                    'detail' => 'New goal added',
                ];
                continue;
            }

            $matchedBaselineLineageKeys[$matchedKey] = true;

            $diffs = [];
            $oldProgress = (float) ($old['progress_pct'] ?? 0);
            $newProgress = (float) $goal->progress_pct;
            if (abs($oldProgress - $newProgress) >= 0.01) {
                $diffs[] = "Progress: {$oldProgress}% → {$newProgress}%";
            }

            $oldStatus = $old['status'] ?? 'not_started';
            $newStatus = $goal->status ?? 'not_started';
            if ($oldStatus !== $newStatus) {
                $diffs[] = "Status: {$oldStatus} → {$newStatus}";
            }

            $oldPillar = $old['pillar'] ?? null;
            $newPillar = $goal->pillar ?? null;
            if ($oldPillar !== $newPillar && $oldPillar && $newPillar) {
                $diffs[] = "Pillar: {$oldPillar} → {$newPillar}";
            }

            if ($old['title'] !== $goal->title) {
                $diffs[] = "Title: \"{$old['title']}\" → \"{$goal->title}\"";
            }

            if (! empty($diffs)) {
                $changes[] = [
                    'type' => 'updated',
                    'goal' => $goal->title,
                    'detail' => implode('; ', $diffs),
                ];
            }
        }

        foreach ($baseline as $item) {
            $lineageKey = $item['origin_goal_id'] ?? $item['id'];
            if (! isset($matchedBaselineLineageKeys[$lineageKey])) {
                $changes[] = [
                    'type' => 'removed',
                    'goal' => $item['title'],
                    'detail' => 'Goal removed from previous version',
                ];
            }
        }

        return [
            'has_snapshot' => true,
            'baseline_label' => $baselineLabel,
            'changes' => $changes,
        ];
    }
}
