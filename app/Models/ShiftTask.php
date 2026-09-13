<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Services\MyDay\ShiftTaskWorkService;
use App\Support\ShiftTaskSupport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftTask extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'label',
        'scheduled_time',
        'is_completed',
        'completed_at',
        'completed_by',
        'reminder_sent_at',
        'sort_order',
        'task_scope',
        'client_id',
        'created_by',
        'creation_key',
        'creation_hash',
        'scheduled_at',
        'version',
        'steps',
        'source_handover_id',
        'source_item_key',
        'source_task_id',
        'help_requested_to',
        'help_reason',
        'help_status',
        'help_requested_at',
        'help_responded_at',
    ];

    protected $casts = [
        'scheduled_time' => 'string',
        'is_completed' => 'bool',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'scheduled_at' => 'immutable_datetime',
        'version' => 'integer',
        'steps' => 'encrypted:array',
        'help_reason' => 'encrypted',
        'help_requested_at' => 'datetime',
        'help_responded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $task): void {
            if ($task->isDirty('is_completed') && $task->is_completed) {
                app(ShiftTaskWorkService::class)->assertStepsFinished($task, true);
            }
            // Roster and attendance still edit the canonical task. Keep their
            // writes visible to My Day's stale-state / undo checks as well.
            if ($task->isDirty(['is_completed', 'label', 'scheduled_time', 'scheduled_at', 'steps']) && ! $task->isDirty('version')) {
                $task->version = ((int) $task->getOriginal('version')) + 1;
            }
        });
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function helpRecipient()
    {
        return $this->belongsTo(User::class, 'help_requested_to');
    }

    public function scheduledFor(): ?CarbonImmutable
    {
        if ($this->scheduled_at) {
            return CarbonImmutable::instance($this->scheduled_at);
        }

        $time = ShiftTaskSupport::normalizeTime($this->scheduled_time);
        if (! $time) {
            return null;
        }

        $shift = $this->relationLoaded('shift') ? $this->shift : $this->shift()->first();
        if (! $shift?->starts_at) {
            return null;
        }

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $startsAt = CarbonImmutable::instance($shift->starts_at)->timezone($timezone);
        $scheduled = $startsAt->setTimeFromTimeString($time);

        return $scheduled->lt($startsAt) ? $scheduled->addDay() : $scheduled;
    }
}
