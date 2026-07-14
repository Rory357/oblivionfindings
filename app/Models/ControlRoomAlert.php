<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Models\ControlRoom\AlertDiscussion;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\TimeEntry;
use App\Models\ControlRoom\TriageQueue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ControlRoomAlert extends Model
{
    use Concerns\HasReferenceNumber;
    use HasFactory;

    public const REFERENCE_PREFIX = 'CR';

    // --- Lifecycle statuses ---
    public const STATUS_OPEN = 'open';

    public const STATUS_ACK = 'ack';

    public const STATUS_TRIAGING = 'triaging';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    // Sensor triage outcomes (Gap B): an operator confirms a detection into an
    // incident, or dismisses it as a false positive (sensor-tuning signal).
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    public const VALID_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACK,
        self::STATUS_TRIAGING,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_CONFIRMED,
        self::STATUS_DISMISSED,
    ];

    /**
     * Positive allowlist for alerts that still require an operational action.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACK,
        self::STATUS_TRIAGING,
        self::STATUS_CONFIRMED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_DISMISSED,
    ];

    /**
     * Valid state transitions. Each key lists the statuses it may transition TO.
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_ACK, self::STATUS_CONFIRMED, self::STATUS_DISMISSED],
        self::STATUS_ACK => [self::STATUS_TRIAGING, self::STATUS_CONFIRMED, self::STATUS_DISMISSED],
        self::STATUS_TRIAGING => [self::STATUS_RESOLVED, self::STATUS_CONFIRMED, self::STATUS_DISMISSED],
        self::STATUS_CONFIRMED => [self::STATUS_RESOLVED],
        self::STATUS_DISMISSED => [],
        self::STATUS_RESOLVED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    public const MAX_ESCALATION_LEVEL = 5;

    protected $fillable = [
        'reference_number',
        'source',
        'alert_type',
        'severity',
        'status',
        'asset_id',
        'fleet_signal_id',
        'device_id',
        'queue_id',
        'playbook_run_id',
        'site_id',
        'client_id',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
        'closed_at',
        'closed_by_user_id',
        'escalated_at',
        'escalated_by_user_id',
        'escalation_level',
        'assigned_to_user_id',
        'assigned_at',
        'assigned_by_user_id',
        'created_by_user_id',
        'context',
        'notes',
        'priority',
        'due_at',
        'category',
        'resolution_code',
        'time_spent_minutes',
        'watchers_count',
        'snoozed_until',
        'snoozed_by_user_id',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'assigned_at' => 'datetime',
        'context' => 'array',
        'escalation_level' => 'integer',
        'due_at' => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The canonical incident linked through the incident's direct alert FK.
     */
    public function clientIncident(): HasOne
    {
        return $this->hasOne(ClientIncident::class, 'control_room_alert_id');
    }

    /**
     * The canonical H&S event linked through its direct alert FK.
     */
    public function hsEvent(): HasOne
    {
        return $this->hasOne(HsEvent::class, 'control_room_alert_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fleetSignal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }

    /**
     * The CR device projection linked to this alert.
     * For canonical device identity, use: $alert->device?->canonicalDevice
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(TriageQueue::class, 'queue_id');
    }

    public function playbookRun(): BelongsTo
    {
        return $this->belongsTo(PlaybookRun::class, 'playbook_run_id');
    }

    public function signals()
    {
        return $this->hasMany(Signal::class, 'alert_id');
    }

    public function sla()
    {
        return $this->hasOne(AlertSla::class, 'alert_id');
    }

    public function evidencePacks()
    {
        return $this->hasMany(EvidencePack::class, 'alert_id');
    }

    public function communications()
    {
        return $this->hasMany(Communication::class, 'alert_id');
    }

    public function operatorNotes()
    {
        return $this->hasMany(OperatorNote::class, 'alert_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by_user_id');
    }

    public function tasks()
    {
        return $this->hasMany(AlertTask::class, 'alert_id');
    }

    public function discussions()
    {
        return $this->hasMany(AlertDiscussion::class, 'alert_id');
    }

    public function watchers()
    {
        return $this->hasMany(AlertWatcher::class, 'alert_id');
    }

    public function timeEntries()
    {
        return $this->hasMany(TimeEntry::class, 'alert_id');
    }

    /**
     * Scope for open alerts.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope for unresolved alerts.
     */
    public function scopeUnresolved($query)
    {
        return $query->actionable();
    }

    /**
     * Scope for alerts that still require an operational action.
     */
    public function scopeActionable($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Scope for high priority alerts.
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    /**
     * Scope for alerts currently snoozed (window still in the future).
     */
    public function scopeSnoozed($query)
    {
        return $query
            ->actionable()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '>', now());
    }

    /**
     * Scope for alerts NOT currently snoozed — never snoozed, or the window has
     * elapsed (an expired snooze auto-returns the alert to the worklist).
     */
    public function scopeNotSnoozed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
        });
    }

    /**
     * Whether the alert is snoozed right now.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * Scope for assigned to a specific user.
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    // --- Lifecycle validation ---

    protected static function booted(): void
    {
        static::creating(function (self $alert): void {
            // Ensure triggered_at is never null
            $alert->triggered_at ??= now();

            // Ensure status is valid
            if (! in_array($alert->status, self::VALID_STATUSES, true)) {
                $alert->status = self::STATUS_OPEN;
            }

            // Normalise severity
            $alert->severity = AlertSeverity::normalise($alert->severity);

            // Bound escalation level
            $alert->escalation_level = min((int) ($alert->escalation_level ?? 0), self::MAX_ESCALATION_LEVEL);
        });

        static::updating(function (self $alert): void {
            // Normalise severity on update
            if ($alert->isDirty('severity')) {
                $alert->severity = AlertSeverity::normalise($alert->severity);
            }

            // Bound escalation level on update
            if ($alert->isDirty('escalation_level')) {
                $alert->escalation_level = min((int) $alert->escalation_level, self::MAX_ESCALATION_LEVEL);
            }
        });
    }

    /**
     * Check if a status transition is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if (! in_array($newStatus, self::VALID_STATUSES, true)) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * Check if the alert is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Check if the alert is actionable (can receive triage actions).
     */
    public function isActionable(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
