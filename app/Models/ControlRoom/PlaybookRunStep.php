<?php

namespace App\Models\ControlRoom;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookRunStep extends Model
{
    protected $table = 'control_room_playbook_run_steps';

    protected $fillable = [
        'playbook_run_id',
        'playbook_step_id',
        'order',
        'status',
        'completed_by_user_id',
        'started_at',
        'completed_at',
        'decision_taken',
        'notes',
        'evidence',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PlaybookRun::class, 'playbook_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(PlaybookStep::class, 'playbook_step_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function complete(User $user, ?string $notes = null, ?array $evidence = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $user->id,
            'notes' => $notes,
            'evidence' => $evidence,
        ]);
    }

    public function skip(User $user, ?string $reason = null): void
    {
        $this->update([
            'status' => 'skipped',
            'completed_at' => now(),
            'completed_by_user_id' => $user->id,
            'notes' => $reason,
        ]);
    }

    public function recordDecision(string $decision, User $user): void
    {
        $this->update([
            'decision_taken' => $decision,
            'completed_by_user_id' => $user->id,
        ]);
    }

    public function addEvidence(array $evidenceItem): void
    {
        $existing = $this->evidence ?? [];
        $existing[] = $evidenceItem;
        $this->update(['evidence' => $existing]);
    }
}
