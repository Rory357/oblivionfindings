<?php

namespace App\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SiteRoom extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'site_rooms';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'name',
        'sort_order',
        'linked_room_type',
        'linked_room_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Residential occupancy extension, when this physical space is residential. */
    public function residentialRoom(): HasOne
    {
        return $this->hasOne(SiteHouseRoom::class, 'site_room_id');
    }

    /** @deprecated Use DeviceAssignment where assignable_type='room' instead. LocationHardware remains only as a compatibility shadow / historical bridge. */
    public function hardware(): HasMany
    {
        return $this->hasMany(LocationHardware::class, 'room_id');
    }

    /** Canonical Security & Devices assignment history for this room. */
    public function deviceAssignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class, 'assignable_id')
            ->where('assignable_type', DeviceAssignment::TARGET_ROOM);
    }

    /**
     * Canonical Devices currently placed in this room.
     *
     * SiteRoom is a placement target only. Device identity and lifecycle stay
     * in the Security & Devices registry; the legacy hardware relation above
     * remains read-only compatibility history.
     */
    public function activeDevices(): BelongsToMany
    {
        return $this->belongsToMany(
            Device::class,
            'device_assignments',
            'assignable_id',
            'device_id',
        )
            ->wherePivot('assignable_type', DeviceAssignment::TARGET_ROOM)
            ->wherePivotNull('released_at')
            ->withPivot([
                'assignment_type',
                'assigned_at',
                'assigned_by_user_id',
            ]);
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /**
     * Resolve the linked room entity based on the polymorphic type.
     */
    public function linkedRoom(): ?Model
    {
        if ($this->linked_room_type === null || $this->linked_room_id === null) {
            return null;
        }

        $linkCount = self::query()
            ->where('linked_room_type', $this->linked_room_type)
            ->where('linked_room_id', $this->linked_room_id)
            ->count();
        if ($linkCount !== 1) {
            return null;
        }

        return match ($this->linked_room_type) {
            'house_room' => SiteHouseRoom::query()
                ->whereKey($this->linked_room_id)
                ->where('site_id', $this->site_id)
                ->where('site_room_id', $this->id)
                ->first(),
            'ho_resource' => SiteHoResource::query()
                ->whereKey($this->linked_room_id)
                ->where('site_id', $this->site_id)
                ->first(),
            'facility_zone' => SiteFacilityZone::query()
                ->whereKey($this->linked_room_id)
                ->where('site_id', $this->site_id)
                ->first(),
            default => null,
        };
    }
}
