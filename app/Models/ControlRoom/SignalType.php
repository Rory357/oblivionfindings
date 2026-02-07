<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalType extends Model
{
    protected $table = 'control_room_signal_types';

    protected $fillable = [
        'code',
        'name',
        'category',
        'default_severity',
        'default_escalation_minutes',
        'debounce_seconds',
        'description',
        'required_context',
        'correlation_keys',
        'is_active',
    ];

    protected $casts = [
        'required_context' => 'array',
        'correlation_keys' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            if (empty($type->name) && !empty($type->code)) {
                $type->name = str_replace('_', ' ', $type->code);
            }
        });
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'signal_type_id');
    }

    public function signalRules(): HasMany
    {
        return $this->hasMany(SignalRule::class, 'signal_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    // Signal categories
    public const CATEGORY_PEOPLE_SAFETY = 'people_safety';
    public const CATEGORY_MEDICAL_WELLBEING = 'medical_wellbeing';
    public const CATEGORY_HOME_FACILITY = 'home_facility';
    public const CATEGORY_FLEET = 'fleet';
    public const CATEGORY_ASSETS = 'assets';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_COMPLIANCE = 'compliance';

    public static function categories(): array
    {
        return [
            self::CATEGORY_PEOPLE_SAFETY => 'People Safety',
            self::CATEGORY_MEDICAL_WELLBEING => 'Medical & Wellbeing',
            self::CATEGORY_HOME_FACILITY => 'Home/Facility',
            self::CATEGORY_FLEET => 'Fleet/Vehicles',
            self::CATEGORY_ASSETS => 'Assets',
            self::CATEGORY_SECURITY => 'Security',
            self::CATEGORY_COMPLIANCE => 'Compliance',
        ];
    }
}
