<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookRun extends Model
{
    protected $table = 'control_room_playbook_runs';

    protected $fillable = [
        'playbook_id',
        'alert_id',
        'status',
        'current_step',
        'completed_steps',
        'total_steps',
        'started_at',
        'completed_at',
        'sla_acknowledge_met',
        'sla_response_met',
        'sla_resolution_met',
        'started_by_user_id',
        'completed_by_user_id',
        'context',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'sla_acknowledge_met' => 'boolean',
        'sla_response_met' => 'boolean',
        'sla_resolution_met' => 'boolean',
        'context' => 'array',
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(PlaybookRunStep::class, 'playbook_run_id')->orderBy('order');
    }

    public function evidencePacks(): HasMany
    {
        return $this->hasMany(EvidencePack::class, 'playbook_run_id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class, 'playbook_run_id');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function start(User $user): void
    {
        // Guard: cannot start an already-started or completed run
        if ($this->status !== self::STATUS_PENDING) {
            return;
        }

        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'started_by_user_id' => $user->id,
        ]);

        $this->initialiseSteps();
    }

    /**
     * Create run steps from the playbook's step definitions.
     *
     * Sets the first step to in_progress, all others to pending.
     * Used by both manual start (via User) and system auto-start.
     *
     * Guards:
     * - Will not create steps if steps already exist (idempotent)
     * - Will not proceed if playbook has zero steps
     */
    public function initialiseSteps(): void
    {
        // Guard: don't duplicate steps on repeated calls
        if ($this->steps()->count() > 0) {
            return;
        }

        $steps = $this->playbook->steps()->orderBy('order')->get();

        // Guard: playbook with zero steps cannot be meaningfully started
        if ($steps->isEmpty()) {
            $this->update([
                'status' => self::STATUS_COMPLETED,
                'total_steps' => 0,
                'completed_at' => now(),
            ]);

            return;
        }

        $this->update(['total_steps' => $steps->count()]);

        foreach ($steps as $index => $step) {
            PlaybookRunStep::create([
                'playbook_run_id' => $this->id,
                'playbook_step_id' => $step->id,
                'order' => $index,
                'status' => $index === 0 ? 'in_progress' : 'pending',
                'started_at' => $index === 0 ? now() : null,
            ]);
        }
    }

    public function complete(User $user): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $user->id,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    public function advanceToNextStep(): ?PlaybookRunStep
    {
        $currentStep = $this->steps()->where('status', 'in_progress')->first();

        if ($currentStep) {
            $currentStep->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            $this->increment('completed_steps');
        }

        $nextStep = $this->steps()
            ->where('status', 'pending')
            ->orderBy('order')
            ->first();

        if ($nextStep) {
            $nextStep->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            $this->update(['current_step' => $nextStep->order]);
            return $nextStep;
        }

        return null;
    }

    public function getProgressPercentage(): int
    {
        if ($this->total_steps === 0) {
            return 0;
        }

        return (int) round(($this->completed_steps / $this->total_steps) * 100);
    }

    // Run statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
}
