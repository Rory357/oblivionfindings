<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotifiableIncident extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'incident_type',
        'notification_authority',
        'title',
        'description',
        'related_incident_id',
        'severity',
        'status',
        'occurred_at',
        'discovered_at',
        'notified_at',
        'notification_reference',
        'notified_by',
        'submitted_by',
        'evidence',
        'outcome',
        'closed_at',
        'closed_by',
        'notification_deadline',
        'site_preserved',
        'site_preservation_released_at',
        'site_preservation_released_by',
        'authority_response_tracking',
        'closure_certified_by',
        'closure_certified_at',
        'investigation_findings',
        'preventive_actions',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'discovered_at' => 'datetime',
        'notified_at' => 'datetime',
        'closed_at' => 'datetime',
        'evidence' => 'array',
        'notification_deadline' => 'datetime',
        'site_preserved' => 'boolean',
        'site_preservation_released_at' => 'datetime',
        'closure_certified_at' => 'datetime',
        'authority_response_tracking' => 'array',
        'preventive_actions' => 'array',
    ];

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function notifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sitePreservationReleasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'site_preservation_released_by');
    }

    public function closureCertifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closure_certified_by');
    }

    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ClientIncident::class, 'related_incident_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isNotified(): bool
    {
        return $this->status === 'notified';
    }

    public function markNotified(int $userId, ?string $reference = null): void
    {
        $this->update([
            'status' => 'notified',
            'notified_at' => now(),
            'notified_by' => $userId,
            'notification_reference' => $reference,
        ]);
    }

    public function close(int $userId, string $outcome): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $userId,
            'outcome' => $outcome,
        ]);
    }

    public function getAuthorityLabel(): string
    {
        return match($this->notification_authority) {
            'worksafe' => 'WorkSafe New Zealand',
            'health_nz' => 'Health New Zealand / Te Whatu Ora',
            'privacy_commissioner' => 'Office of the Privacy Commissioner',
            'charities_services' => 'Charities Services',
            default => $this->notification_authority,
        };
    }

    public function getIncidentTypeLabel(): string
    {
        return match($this->incident_type) {
            'death' => 'Death',
            'serious_harm' => 'Serious Harm',
            'serious_injury' => 'Serious Injury',
            'health_safety' => 'Health & Safety',
            'privacy_breach' => 'Privacy Breach',
            default => $this->incident_type,
        };
    }
}
