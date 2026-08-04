<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @deprecated Use DeviceAssignment where assignable_type='room' instead. LocationHardware remains only as a compatibility shadow / historical bridge. */
    public function hardware(): HasMany
    {
        return $this->hasMany(LocationHardware::class, 'room_id');
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
        return match ($this->linked_room_type) {
            'house_room' => SiteHouseRoom::find($this->linked_room_id),
            'ho_resource' => SiteHoResource::find($this->linked_room_id),
            'facility_zone' => SiteFacilityZone::find($this->linked_room_id),
            default => null,
        };
    }
}
