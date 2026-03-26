<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationRound extends Model
{
    use HasFactory, AuditableChanges;

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
    ];

    protected $casts = [
        'round_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function isOverdue(): bool
    {
        if ($this->status !== 'pending') return false;
        $scheduledAt = $this->round_date->copy()->setTimeFromTimeString($this->scheduled_time);
        return now()->gt($scheduledAt->addMinutes($this->window_minutes));
    }

    public function getCompletionPercentageAttribute(): float
    {
        if ($this->total_medications === 0) return 0;
        return round(($this->administered_count / $this->total_medications) * 100, 1);
    }

    public function updateCounts(): void
    {
        $this->administered_count = $this->administrations()->where('status', 'given')->count();
        $this->refused_count = $this->administrations()->where('status', 'refused')->count();
        $this->withheld_count = $this->administrations()->where('status', 'withheld')->count();
        $this->missed_count = $this->administrations()->where('status', 'missed')->count();
        $this->save();
    }
}
