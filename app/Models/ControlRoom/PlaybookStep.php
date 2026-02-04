<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookStep extends Model
{
    protected $table = 'control_room_playbook_steps';

    protected $fillable = [
        'playbook_id',
        'order',
        'title',
        'instructions',
        'type',
        'is_required',
        'is_blocking',
        'decision_options',
        'notify_config',
        'evidence_config',
        'time_limit_minutes',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_blocking' => 'boolean',
        'decision_options' => 'array',
        'notify_config' => 'array',
        'evidence_config' => 'array',
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class, 'playbook_id');
    }

    public function runSteps(): HasMany
    {
        return $this->hasMany(PlaybookRunStep::class, 'playbook_step_id');
    }

    // Step types
    public const TYPE_TASK = 'task';
    public const TYPE_DECISION = 'decision';
    public const TYPE_NOTIFICATION = 'notification';
    public const TYPE_ESCALATION = 'escalation';
    public const TYPE_EVIDENCE = 'evidence';
    public const TYPE_APPROVAL = 'approval';

    public static function types(): array
    {
        return [
            self::TYPE_TASK => 'Task',
            self::TYPE_DECISION => 'Decision Point',
            self::TYPE_NOTIFICATION => 'Send Notification',
            self::TYPE_ESCALATION => 'Escalate',
            self::TYPE_EVIDENCE => 'Collect Evidence',
            self::TYPE_APPROVAL => 'Approval Required',
        ];
    }
}
