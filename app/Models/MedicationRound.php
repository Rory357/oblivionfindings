<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationRound extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'service_context_id',
        'site_id',
        'name',
        'round_template_id',
        'round_type',
        'scheduled_time',
        'window_minutes',
        'round_date',
        'status',
        'assigned_to',
        'started_by',
        'completed_by',
        'started_at',
        'completed_at',
        'total_medications',
        'administered_count',
        'refused_count',
        'withheld_count',
        'missed_count',
        'notes',
        'review_required',
        'review_reason',
        'review_flagged_at',
        'review_flagged_by',
    ];

    protected $casts = [
        'round_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'review_required' => 'boolean',
        'review_flagged_at' => 'datetime',
        'window_minutes' => 'integer',
        'total_medications' => 'integer',
        'administered_count' => 'integer',
        'refused_count' => 'integer',
        'withheld_count' => 'integer',
        'missed_count' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(MedicationRoundTemplate::class, 'round_template_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reviewFlaggedBy()
    {
        return $this->belongsTo(User::class, 'review_flagged_by');
    }

    public function administrations()
    {
        return $this->hasMany(ClientMedicationAdministration::class, 'medication_round_id');
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('round_date', $date);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * The round's time: its NZ date and time (wall-clock), as an instant in
     * the worker timezone. Null without a time.
     */
    public function scheduledAt(): ?Carbon
    {
        if (! $this->round_date || ! $this->scheduled_time) {
            return null;
        }

        return Carbon::parse(
            $this->round_date->toDateString().' '.substr((string) $this->scheduled_time, 0, 5),
            (string) config('app.worker_timezone', 'Pacific/Auckland'),
        );
    }

    /** The round's window, its time ± window_minutes (60 when unset). */
    public function windowMinutes(): int
    {
        return max(0, (int) ($this->window_minutes ?? 60));
    }

    public function windowEndsAt(): ?Carbon
    {
        return $this->scheduledAt()?->addMinutes($this->windowMinutes());
    }

    /** Not started once its window has ended. */
    public function isOverdue(): bool
    {
        $endsAt = $this->windowEndsAt();

        return $this->status === 'pending' && $endsAt !== null && now()->gt($endsAt);
    }

    public function getCompletionPercentageAttribute(): float
    {
        if ($this->total_medications === 0) {
            return 0;
        }

        return round(($this->administered_count / $this->total_medications) * 100, 1);
    }

    public function updateCounts(): void
    {
        $effectiveAdministrations = $this->administrations()->effectiveClinicalEvidence();
        $this->administered_count = (clone $effectiveAdministrations)->where('status', 'given')->count();
        $this->refused_count = (clone $effectiveAdministrations)->where('status', 'refused')->count();
        $this->withheld_count = (clone $effectiveAdministrations)->where('status', 'withheld')->count();
        $this->missed_count = (clone $effectiveAdministrations)->where('status', 'missed')->count();
        $this->save();
    }
}
