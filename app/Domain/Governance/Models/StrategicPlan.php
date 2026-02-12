<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_by_board_at' => 'datetime',
        'values' => 'array',
        'version_number' => 'integer',
    ];

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
        return $query->where('status', 'approved');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function approve(int $resolutionId): void
    {
        $this->update([
            'status' => 'approved',
            'approval_resolution_id' => $resolutionId,
            'approved_by_board_at' => now(),
        ]);
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
        $newPlan = $this->replicate([
            'approval_resolution_id',
            'approved_by_board_at',
        ]);
        $newPlan->fill([
            'version_number' => $this->version_number + 1,
            'version_notes' => $notes,
            'supersedes_plan_id' => $this->id,
            'status' => 'draft',
            'created_by' => $userId,
        ]);
        $newPlan->save();

        // Copy goals
        foreach ($this->goals as $goal) {
            $newGoal = $goal->replicate();
            $newGoal->strategic_plan_id = $newPlan->id;
            $newGoal->save();
        }

        return $newPlan;
    }

    /**
     * Capture a snapshot of current goals/progress for "what changed" comparison.
     */
    public function captureSnapshot(): void
    {
        $snapshot = $this->goals->map(fn($g) => [
            'id' => $g->id,
            'title' => $g->title,
            'pillar' => $g->pillar ?? null,
            'progress_pct' => $g->progress_pct,
            'status' => $g->status ?? null,
        ])->toArray();

        $this->update(['last_snapshot' => $snapshot]);
    }

    /**
     * Get what changed since the last captured snapshot.
     */
    public function getChangesSinceLastSnapshot(): array
    {
        if (empty($this->last_snapshot)) {
            return ['has_snapshot' => false, 'changes' => []];
        }

        $previous = collect($this->last_snapshot)->keyBy('id');
        $current = $this->goals->fresh();
        $changes = [];

        foreach ($current as $goal) {
            $old = $previous->get($goal->id);
            if (!$old) {
                $changes[] = ['type' => 'added', 'goal' => $goal->title, 'detail' => 'New goal added'];
                continue;
            }

            $diffs = [];
            if (($old['progress_pct'] ?? 0) != $goal->progress_pct) {
                $diffs[] = "Progress: {$old['progress_pct']}% → {$goal->progress_pct}%";
            }
            if (($old['status'] ?? null) !== ($goal->status ?? null)) {
                $diffs[] = "Status: {$old['status']} → {$goal->status}";
            }
            if (!empty($diffs)) {
                $changes[] = [
                    'type' => 'updated',
                    'goal' => $goal->title,
                    'detail' => implode('; ', $diffs),
                ];
            }
        }

        // Check for removed goals
        $currentIds = $current->pluck('id')->toArray();
        foreach ($previous as $id => $old) {
            if (!in_array($id, $currentIds)) {
                $changes[] = ['type' => 'removed', 'goal' => $old['title'], 'detail' => 'Goal removed'];
            }
        }

        return ['has_snapshot' => true, 'changes' => $changes];
    }
}
