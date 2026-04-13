<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
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
        'assignment_type',
        'assigned_at',
        'expected_return_at',
        'released_at',
        'assigned_by_user_id',
        'released_by_user_id',
        'consent_id',
        'notes',
    ];

    protected $casts = [
        'assignment_type' => AssignmentType::class,
        'assigned_at' => 'datetime',
        'expected_return_at' => 'datetime',
        'released_at' => 'datetime',
    ];

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
}
