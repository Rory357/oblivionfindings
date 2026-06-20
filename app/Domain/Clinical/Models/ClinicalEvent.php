<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Concerns\AuditableChanges;
use App\Models\HsEvent;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalEvent extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Clinical\ClinicalEventFactory::new();
    }

    protected $table = 'clinical_events';

    protected $fillable = [
        'client_id',
        'shift_id',
        'site_id',
        'reported_by',
        'event_type',
        'severity',
        'occurred_at',
        'reported_at',
        'description',
        'immediate_action_taken',
        'outcome',
        'witnesses',
        'linked_hs_event_id',
        'linked_incident_id',
        'requires_followup',
        'followup_notes',
        'followup_completed_at',
        'followup_completed_by',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'event_type' => ClinicalEventType::class,
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'witnesses' => 'array',
        'requires_followup' => 'boolean',
        'followup_completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function followupCompleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followup_completed_by');
    }

    public function linkedHsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'linked_hs_event_id');
    }

    public function linkedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'linked_incident_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClinicalAttachment::class, 'attachable')->latest();
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOfType($query, ClinicalEventType $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', [AlertSeverity::HIGH, AlertSeverity::CRITICAL]);
    }

    public function scopeForShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function shouldLinkToHs(): bool
    {
        return $this->event_type->shouldLinkToHs();
    }

    public function isHighSeverity(): bool
    {
        return AlertSeverity::isAtLeast($this->severity, AlertSeverity::HIGH);
    }
}
