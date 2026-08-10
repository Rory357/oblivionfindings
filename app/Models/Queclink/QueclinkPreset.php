<?php

namespace App\Models\Queclink;

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

/**
 * A saved bundle of Queclink configuration sections that an operator can apply
 * to a paired device (or many) in one click. System presets ship with the
 * product (is_system true); operators can also save application-wide presets.
 *
 * The {@see $payload} is a map of section name => field/value pairs, using the
 * same section keys the write path understands (server, tracking, pin, dog,
 * time, non_movement, power, wifi, geo, bluetooth, beacons, allowlist,
 * firmware_update, firmware_version).
 */
class QueclinkPreset extends Model
{
    use WritesLegacyStorageContext;

    private const RETIREMENT_FIELDS = [
        'retired_at',
        'retired_by_user_id',
        'retirement_reason',
    ];

    private static int $governedRetirementDepth = 0;

    protected $table = 'queclink_presets';

    protected $fillable = [
        'device_configuration_profile_id',
        'name',
        'slug',
        'description',
        'target_category',
        'payload',
        'is_system',
        'created_by_user_id',
        'retired_at',
        'retired_by_user_id',
        'retirement_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_system' => 'boolean',
        'retired_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $preset): void {
            if ($preset->retired_at !== null
                || $preset->retired_by_user_id !== null
                || $preset->retirement_reason !== null) {
                throw new UnexpectedValueException('New Queclink presets cannot begin as retired evidence.');
            }
        });
        self::deleting(function (): never {
            throw new UnexpectedValueException('Queclink presets are retained as governed configuration evidence.');
        });
        self::updating(function (self $preset): void {
            if (self::$governedRetirementDepth > 0) {
                return;
            }

            $dirty = array_keys($preset->getDirty());
            if (array_intersect($dirty, self::RETIREMENT_FIELDS) !== []) {
                throw new UnexpectedValueException(
                    'Queclink preset retirement evidence can only change through the governed retirement transition.',
                );
            }
            if ($preset->getRawOriginal('retired_at') !== null
                && array_diff($dirty, ['updated_at']) !== []) {
                throw new UnexpectedValueException('Retired Queclink preset evidence is immutable.');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function configurationProfile(): BelongsTo
    {
        return $this->belongsTo(DeviceConfigurationProfile::class, 'device_configuration_profile_id');
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function retireGoverned(int $actorId, string $reason): void
    {
        if ($this->isRetired()) {
            throw new UnexpectedValueException('This Queclink preset has already been retired.');
        }

        self::$governedRetirementDepth++;
        try {
            $this->forceFill([
                'retired_at' => now(),
                'retired_by_user_id' => $actorId,
                'retirement_reason' => trim($reason),
            ])->save();
        } finally {
            self::$governedRetirementDepth--;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function sectionPayloads(): array
    {
        $payload = $this->configurationProfile?->sectionPayloads() ?? $this->payload ?? [];

        return array_filter($payload, fn ($section) => is_array($section));
    }
}
