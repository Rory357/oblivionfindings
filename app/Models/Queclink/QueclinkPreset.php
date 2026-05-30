<?php

namespace App\Models\Queclink;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved bundle of Queclink configuration sections that an operator can apply
 * to a paired device (or many) in one click. System presets ship with the
 * product (tenant_id null, is_system true); operators can also save their own.
 *
 * The {@see $payload} is a map of section name => field/value pairs, using the
 * same section keys the write path understands (server, tracking, pin, dog,
 * time, non_movement, power, wifi, geo, bluetooth, beacons, allowlist,
 * firmware_update, firmware_version).
 */
class QueclinkPreset extends Model
{
    protected $table = 'queclink_presets';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'target_category',
        'payload',
        'is_system',
        'created_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_system' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Presets an operator in the given tenant may see: the shared system
     * library plus anything their own tenant has saved.
     */
    public function scopeAvailableTo(Builder $query, ?int $tenantId): Builder
    {
        return $query->where(function (Builder $q) use ($tenantId) {
            $q->where('is_system', true);

            if ($tenantId !== null) {
                $q->orWhere('tenant_id', $tenantId);
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    public function sectionPayloads(): array
    {
        $payload = $this->payload ?? [];

        return array_filter($payload, fn ($section) => is_array($section));
    }
}
