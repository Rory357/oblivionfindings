<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ShiftGpsLog extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'shift_id',
        'user_id',
        'event_type',
        'latitude',
        'longitude',
        'accuracy',
        'address',
        'captured_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'captured_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            $log->unsetRelation('shift');
            $log->loadMissing('shift.client:id,site_id');

            $shift = $log->shift;
            $shiftSiteId = $shift?->site_id ?: $shift?->client?->site_id;
            $siteConflict = $shift?->site_id !== null
                && $shift?->client?->site_id !== null
                && (int) $shift->site_id !== (int) $shift->client->site_id;

            if (! $shift
                || ! $shift->user_id
                || (int) $shift->user_id !== (int) $log->user_id
                || ! $shiftSiteId
                || $siteConflict) {
                throw ValidationException::withMessages([
                    'shift_id' => 'GPS evidence must match the assigned worker and canonical Site of its Shift.',
                ]);
            }
        });
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
