<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientBreakGlassAccess extends Model
{
    use SoftDeletes;

    /** Pre-filled grant duration. */
    public const DEFAULT_MINUTES = 60;

    /** Hard policy cap for a grant window (including extensions): 4 hours. */
    public const MAX_MINUTES = 240;

    /** How long a single "Extend" adds. */
    public const EXTEND_MINUTES = 30;

    protected $fillable = [
        'client_id',
        'user_id',
        'revoked_by',
        'reason',
        'reason_category',
        'authorization_mode',
        'co_signed_by',
        'acknowledged_min_necessary',
        'acknowledged_incident_report',
        'expires_at',
        'policy_snapshot',
        'confirmed_at',
        'ended_at',
        'ended_how',
        'ended_by',
        'end_reason',
        'review_due_at',
        'reviewed_at',
        'reviewed_by',
        'review_outcome',
        'review_notes',
        'incident_report_linked',
        'incident_report_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'policy_snapshot' => 'array',
        'confirmed_at' => 'datetime',
        'ended_at' => 'datetime',
        'review_due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'acknowledged_min_necessary' => 'boolean',
        'acknowledged_incident_report' => 'boolean',
        'incident_report_linked' => 'boolean',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(MedicationEmergencyAccessReview::class, 'access_id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(MedicationEmergencyAccessExtension::class, 'access_id');
    }

    public function effectivePolicy(): array
    {
        // The policy for a legacy grant is unknown. Preserve the historical defaults,
        // never reinterpret it using a policy subsequently edited by someone else.
        return $this->policy_snapshot ?? BreakGlassPolicy::defaults();
    }

    public function isRunning(): bool
    {
        return ! $this->trashed() && $this->ended_at === null
            && $this->expires_at !== null && $this->expires_at->gt(now());
    }

    public function endedTime(): ?CarbonInterface
    {
        return $this->ended_at ?? $this->deleted_at
            ?? ($this->expires_at?->lte(now()) ? $this->expires_at : null);
    }

    public function reviewDueTime(): ?CarbonInterface
    {
        return $this->review_due_at ?? $this->endedTime()?->copy()->addDays($this->effectivePolicy()['review_days']);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function coSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_signed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_report_id');
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(BreakGlassAccessEvent::class, 'break_glass_access_id');
    }

    /** Short attribution label for the grant card / audit ("No second person asked", "Co-signed by …"). */
    public function authorizationLabel(): ?string
    {
        if ($this->authorization_mode === 'co_sign') {
            return 'Co-signed by '.($this->coSignedBy?->name ?? 'second approver');
        }
        if ($this->authorization_mode === 'self') {
            return 'No second person asked';
        }

        return null; // legacy grants have no recorded mode
    }
}
