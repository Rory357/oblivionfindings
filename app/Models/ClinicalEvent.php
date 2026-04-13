<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalEvent extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_FALL = 'fall';
    public const TYPE_SEIZURE = 'seizure';
    public const TYPE_BEHAVIOURAL_EPISODE = 'behavioural_episode';
    public const TYPE_HOSPITAL_ADMISSION = 'hospital_admission';
    public const TYPE_HOSPITAL_DISCHARGE = 'hospital_discharge';
    public const TYPE_GP_VISIT = 'gp_visit';
    public const TYPE_SPECIALIST_APPOINTMENT = 'specialist_appointment';

    public const TYPES = [
        self::TYPE_FALL,
        self::TYPE_SEIZURE,
        self::TYPE_BEHAVIOURAL_EPISODE,
        self::TYPE_HOSPITAL_ADMISSION,
        self::TYPE_HOSPITAL_DISCHARGE,
        self::TYPE_GP_VISIT,
        self::TYPE_SPECIALIST_APPOINTMENT,
    ];

    public const TYPE_LABELS = [
        self::TYPE_FALL => 'Fall',
        self::TYPE_SEIZURE => 'Seizure',
        self::TYPE_BEHAVIOURAL_EPISODE => 'Behavioural Episode',
        self::TYPE_HOSPITAL_ADMISSION => 'Hospital Admission',
        self::TYPE_HOSPITAL_DISCHARGE => 'Hospital Discharge',
        self::TYPE_GP_VISIT => 'GP Visit',
        self::TYPE_SPECIALIST_APPOINTMENT => 'Specialist Appointment',
    ];

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'client_id',
        'shift_id',
        'event_type',
        'severity',
        'occurred_at',
        'description',
        'metadata',
        'follow_up_required',
        'follow_up_completed_at',
        'follow_up_notes',
        'reported_by',
        'reviewed_by',
        'reviewed_at',
        'linked_observation_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'follow_up_required' => 'boolean',
        'follow_up_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function linkedObservation(): BelongsTo
    {
        return $this->belongsTo(ClinicalObservation::class, 'linked_observation_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeNeedsFollowUp($query)
    {
        return $query->where('follow_up_required', true)
            ->whereNull('follow_up_completed_at');
    }

    public function scopeUnreviewed($query)
    {
        return $query->whereNull('reviewed_at');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->event_type] ?? ucfirst($this->event_type);
    }

    public function needsFollowUp(): bool
    {
        return $this->follow_up_required && $this->follow_up_completed_at === null;
    }
}
