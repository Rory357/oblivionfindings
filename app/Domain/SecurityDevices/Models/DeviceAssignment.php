<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Services\DeviceCustodySiteResolver;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\ConsentValidationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssignment extends Model
{
    use AuditableChanges;

    protected $table = 'device_assignments';

    protected $fillable = [
        'device_id',
        'assignable_type',
        'assignable_id',
        'custody_site_id',
        'assignment_type',
        'assigned_at',
        'expected_return_at',
        'released_at',
        'assigned_by_user_id',
        'released_by_user_id',
        'consent_id',
        'tracking_purpose',
        'authority_basis',
        'access_audience',
        'retention_days',
        'collection_started_at',
        'collection_stopped_at',
        'collection_stop_reason',
        'withdrawal_outcome',
        'notes',
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'assigned_at' => 'datetime',
        'expected_return_at' => 'datetime',
        'released_at' => 'datetime',
        'access_audience' => 'array',
        'retention_days' => 'integer',
        'collection_started_at' => 'datetime',
        'collection_stopped_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if ($assignment->custody_site_id === null
                && in_array($assignment->assignable_type, self::VALID_TARGETS, true)
                && is_numeric($assignment->assignable_id)) {
                $assignment->custody_site_id = app(DeviceCustodySiteResolver::class)->tryResolve(
                    (string) $assignment->assignable_type,
                    (int) $assignment->assignable_id,
                );
            }
            $assignment->applyPersonalTrackingGovernanceDefaults();
        });
    }

    // ── Assignable type constants ─────────────────────────────────

    public const TARGET_SITE = 'site';

    public const TARGET_ROOM = 'room';

    public const TARGET_VEHICLE = 'vehicle';

    public const TARGET_STAFF = 'staff';

    public const TARGET_CLIENT = 'client';

    public const VALID_TARGETS = [
        self::TARGET_SITE,
        self::TARGET_ROOM,
        self::TARGET_VEHICLE,
        self::TARGET_STAFF,
        self::TARGET_CLIENT,
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'consent_id');
    }

    /**
     * Resolve the assigned entity.
     */
    public function assignable(): ?Model
    {
        return match ($this->assignable_type) {
            self::TARGET_SITE => Site::find($this->assignable_id),
            self::TARGET_ROOM => SiteRoom::find($this->assignable_id),
            self::TARGET_VEHICLE => Asset::find($this->assignable_id),
            self::TARGET_STAFF => User::find($this->assignable_id),
            self::TARGET_CLIENT => Client::find($this->assignable_id),
            default => null,
        };
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }

    public function scopeReleased($query)
    {
        return $query->whereNotNull('released_at');
    }

    public function scopeCurrent($query)
    {
        return $query
            ->whereNull('released_at')
            ->where('assigned_at', '<=', now());
    }

    public function scopeEffectiveAt($query, \DateTimeInterface $at)
    {
        return $query
            ->where('assigned_at', '<=', $at)
            ->where(function ($window) use ($at): void {
                $window->whereNull('released_at')->orWhere('released_at', '>', $at);
            });
    }

    public function scopeCollectionActive($query)
    {
        return $query
            ->whereNull('released_at')
            ->whereNull('collection_stopped_at');
    }

    public function scopeForTarget($query, string $type, int $id)
    {
        return $query->where('assignable_type', $type)
            ->where('assignable_id', $id);
    }

    public function scopeOverdueLoans($query)
    {
        return $query->where('assignment_type', AssignmentType::Loan->value)
            ->whereNull('released_at')
            ->whereNotNull('expected_return_at')
            ->where('expected_return_at', '<', now());
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isActive()
            && $this->expected_return_at !== null
            && $this->expected_return_at->isPast();
    }

    public function requiresConsent(): bool
    {
        return $this->assignable_type === self::TARGET_CLIENT;
    }

    public function isCollectionActive(): bool
    {
        return $this->released_at === null && $this->collection_stopped_at === null;
    }

    private function applyPersonalTrackingGovernanceDefaults(): void
    {
        if (! in_array($this->assignable_type, [self::TARGET_CLIENT, self::TARGET_STAFF], true)) {
            return;
        }

        $device = $this->device()->first();
        if (! $device || $device->domain !== 'tracking') {
            return;
        }

        $isClient = $this->assignable_type === self::TARGET_CLIENT;
        $consent = $isClient && $this->consent_id
            ? ClientConsent::query()->with('consentType')->find($this->consent_id)
            : null;
        $validConsent = $consent
            && (int) $consent->client_id === (int) $this->assignable_id
            && ConsentValidationService::isValidTrackingConsent($consent, $this->assignable_id);
        $assignedAt = $this->assigned_at ?? now();

        $this->tracking_purpose ??= $isClient
            ? ($consent?->consentType?->purpose
                ?: $consent?->consentType?->name
                ?: 'Client personal safety tracking')
            : 'Staff lone-worker safety';
        $this->authority_basis ??= $isClient
            ? 'assignment_linked_client_consent'
            : 'active_lone_worker_session';
        $this->access_audience ??= $isClient
            ? ['authorised_client_care', 'control_room', 'health_and_safety']
            : ['control_room', 'health_and_safety'];
        $this->retention_days ??= max(1, (int) config('fleet.retention.personal_location_days', 90));
        $this->collection_started_at ??= $assignedAt;

        if ($this->released_at !== null) {
            $this->collection_stopped_at ??= $this->released_at;
            $this->collection_stop_reason ??= 'assignment_released';
            $this->withdrawal_outcome ??= 'collection_stopped_and_live_projection_revoked';

            return;
        }

        if ($isClient && ! $validConsent) {
            $isWithdrawnConsent = $consent
                && ($consent->withdrawn_at !== null || $consent->status === 'withdrawn');

            $this->collection_stopped_at ??= $consent?->withdrawn_at ?? now();
            $this->collection_stop_reason ??= $isWithdrawnConsent
                ? 'consent_withdrawn'
                : 'consent_not_active';
            $this->withdrawal_outcome ??= 'collection_stopped_and_live_projection_revoked';
        }
    }
}
