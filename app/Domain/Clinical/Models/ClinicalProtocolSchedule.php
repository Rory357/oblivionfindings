<?php

namespace App\Domain\Clinical\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\ShiftTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClinicalProtocolSchedule extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Clinical\ClinicalProtocolScheduleFactory::new();
    }

    protected $table = 'clinical_protocol_schedules';

    protected $fillable = [
        'clinical_protocol_id',
        'schedule_version',
        'occurrence_key',
        'due_at',
        'status',
        'skip_reason',
        'completed_by',
        'completed_at',
        'clinical_observation_id',
        'shift_task_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'schedule_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClinicalProtocolSchedule $schedule): void {
            $schedule->schedule_version = (int) ($schedule->schedule_version ?: 1);
            $schedule->occurrence_key = self::buildOccurrenceKey(
                (int) $schedule->clinical_protocol_id,
                $schedule->schedule_version,
                CarbonImmutable::instance($schedule->due_at)->utc()->startOfSecond(),
            );
        });

        static::updating(function (ClinicalProtocolSchedule $schedule): void {
            if ($schedule->isDirty(['clinical_protocol_id', 'schedule_version', 'occurrence_key', 'due_at'])) {
                throw new LogicException('Clinical protocol schedule occurrence identity is immutable.');
            }
        });
    }

    public static function buildOccurrenceKey(
        int $protocolId,
        int $scheduleVersion,
        CarbonImmutable $dueAt,
    ): string {
        return hash('sha256', implode('|', [
            'clinical-protocol-occurrence-v1',
            (string) $protocolId,
            (string) $scheduleVersion,
            $dueAt->utc()->startOfSecond()->format('Y-m-d\TH:i:s\Z'),
        ]));
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ClinicalProtocol::class, 'clinical_protocol_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(ClinicalObservation::class, 'clinical_observation_id');
    }

    public function shiftTask(): BelongsTo
    {
        return $this->belongsTo(ShiftTask::class, 'shift_task_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')->where('due_at', '<', now());
    }

    public function scopeDueBefore($query, $datetime)
    {
        return $query->where('due_at', '<=', $datetime);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at->isPast();
    }

    public function markCompleted(int $userId, ?int $observationId = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_by' => $userId,
            'completed_at' => now(),
            'clinical_observation_id' => $observationId,
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);
    }
}
