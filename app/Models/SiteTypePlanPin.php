<?php

namespace App\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTypePlanPin extends Model
{
    use WritesLegacyStorageContext;

    public const KIND_DEVICE = 'device';

    public const KIND_MEDICATION_STORAGE = 'medication_storage';

    public const KIND_EMERGENCY_EXIT = 'emergency_exit';

    public const KIND_EVACUATION_ROUTE = 'evacuation_route';

    public const KIND_ASSEMBLY_POINT = 'assembly_point';

    public const KIND_YOU_ARE_HERE = 'you_are_here';

    public const KIND_FIRE_EXTINGUISHER = 'fire_extinguisher';

    public const KIND_FIRE_BLANKET = 'fire_blanket';

    public const KIND_FIRE_HOSE_REEL = 'fire_hose_reel';

    public const KIND_FIRE_PANEL = 'fire_panel';

    public const KIND_FIRE_DOOR = 'fire_door';

    public const KIND_SPRINKLER_HEAD = 'sprinkler_head';

    public const KIND_MANUAL_CALL_POINT = 'manual_call_point';

    public const KIND_HYDRANT = 'hydrant';

    public const KIND_FIRST_AID_KIT = 'first_aid_kit';

    public const KIND_SMOKE_ALARM = 'smoke_alarm';

    public const KIND_DEFIBRILLATOR = 'defibrillator';

    public const KIND_EVACUATION_DIAGRAM = 'evacuation_diagram';

    public const KIND_GAS_SHUTOFF = 'gas_shutoff';

    public const KIND_WATER_SHUTOFF = 'water_shutoff';

    public const KIND_ELECTRICAL_PANEL = 'electrical_panel';

    public const KIND_CUSTOM_MARKER = 'custom_marker';

    public const KINDS = [
        self::KIND_DEVICE,
        self::KIND_MEDICATION_STORAGE,
        self::KIND_EMERGENCY_EXIT,
        self::KIND_EVACUATION_ROUTE,
        self::KIND_ASSEMBLY_POINT,
        self::KIND_YOU_ARE_HERE,
        self::KIND_FIRE_EXTINGUISHER,
        self::KIND_FIRE_BLANKET,
        self::KIND_FIRE_HOSE_REEL,
        self::KIND_FIRE_PANEL,
        self::KIND_FIRE_DOOR,
        self::KIND_SPRINKLER_HEAD,
        self::KIND_MANUAL_CALL_POINT,
        self::KIND_HYDRANT,
        self::KIND_FIRST_AID_KIT,
        self::KIND_SMOKE_ALARM,
        self::KIND_DEFIBRILLATOR,
        self::KIND_EVACUATION_DIAGRAM,
        self::KIND_GAS_SHUTOFF,
        self::KIND_WATER_SHUTOFF,
        self::KIND_ELECTRICAL_PANEL,
        self::KIND_CUSTOM_MARKER,
    ];

    public const EMERGENCY_KINDS = [
        self::KIND_EMERGENCY_EXIT,
        self::KIND_EVACUATION_ROUTE,
        self::KIND_ASSEMBLY_POINT,
        self::KIND_YOU_ARE_HERE,
        self::KIND_FIRE_EXTINGUISHER,
        self::KIND_FIRE_BLANKET,
        self::KIND_FIRE_HOSE_REEL,
        self::KIND_FIRE_PANEL,
        self::KIND_FIRE_DOOR,
        self::KIND_SPRINKLER_HEAD,
        self::KIND_MANUAL_CALL_POINT,
        self::KIND_HYDRANT,
        self::KIND_FIRST_AID_KIT,
        self::KIND_SMOKE_ALARM,
        self::KIND_DEFIBRILLATOR,
        self::KIND_EVACUATION_DIAGRAM,
        self::KIND_CUSTOM_MARKER,
    ];

    protected $fillable = [
        'tenant_id',
        'site_type_plan_id',
        'kind',
        'subkind',
        'device_id',
        'room_ref_type',
        'room_ref_id',
        'label',
        'notes',
        'meta',
        'x',
        'y',
        'rotation_deg',
        'width',
        'height',
        'path_points',
        'sort_order',
    ];

    protected $casts = [
        'meta' => 'array',
        'path_points' => 'array',
        'x' => 'decimal:4',
        'y' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'rotation_deg' => 'integer',
        'sort_order' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SiteTypePlan::class, 'site_type_plan_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeForSite($query, Site|int $site)
    {
        $siteId = $site instanceof Site ? $site->id : $site;

        return $query->whereHas('plan', fn ($plan) => $plan->where('site_id', $siteId));
    }
}
