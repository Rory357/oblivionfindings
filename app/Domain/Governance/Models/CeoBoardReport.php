<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CeoBoardReport extends Model
{
    use AuditableChanges;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PRESENTED = 'presented';

    protected $fillable = [
        'governance_meeting_id', 'submitted_by', 'status',
        'period_start', 'period_end',
        'executive_summary',
        'operational_summary', 'key_achievements', 'challenges_and_risks',
        'staffing_update', 'compliance_status', 'financial_summary',
        'recommendations',
        'decisions_sought', 'matters_arising', 'kpi_snapshot',
        'attachments',
        'submitted_at', 'deadline',
        'presented_at', 'presented_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'decisions_sought' => 'array',
        'matters_arising' => 'array',
        'kpi_snapshot' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'submitted_at' => 'datetime',
        'presented_at' => 'datetime',
        'deadline' => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function presentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isPresented(): bool
    {
        return $this->status === self::STATUS_PRESENTED;
    }

    public function isOverdue(): bool
    {
        return $this->isDraft() && $this->deadline && $this->deadline->isPast();
    }

    public function submit(?array $kpiSnapshot = null): void
    {
        $payload = [
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ];

        if ($kpiSnapshot !== null) {
            $payload['kpi_snapshot'] = $kpiSnapshot;
        }

        $this->update($payload);
    }

    public function markPresented(?User $by = null): void
    {
        $this->update([
            'status' => self::STATUS_PRESENTED,
            'presented_at' => now(),
            'presented_by' => $by?->id,
        ]);
    }

    public function periodLabel(): ?string
    {
        if (! $this->period_start || ! $this->period_end) {
            return null;
        }

        $start = $this->period_start;
        $end = $this->period_end;

        if ($start->isSameMonth($end) && $start->isSameYear($end)) {
            return $start->format('F Y');
        }

        if ($start->isSameYear($end)) {
            return $start->format('j M') . ' – ' . $end->format('j M Y');
        }

        return $start->format('j M Y') . ' – ' . $end->format('j M Y');
    }

    public function daysUntilDeadline(): ?int
    {
        if (! $this->deadline) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->deadline->copy()->startOfDay(), false);
    }
}
