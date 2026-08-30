<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class FacilitySignal extends Model
{
    protected $fillable = [
        'site_id',
        'inspection_schedule_id',
        'inspection_record_id',
        'signal_type',
        'severity_hint',
        'occurred_at',
        'idempotency_key',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Facility signal provenance is append-only and immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Facility signal provenance is append-only and immutable.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function inspectionSchedule(): BelongsTo
    {
        return $this->belongsTo(SiteInspectionSchedule::class, 'inspection_schedule_id');
    }

    public function inspectionRecord(): BelongsTo
    {
        return $this->belongsTo(SiteInspectionRecord::class, 'inspection_record_id');
    }

    public function outbox(): HasOne
    {
        return $this->hasOne(FacilitySignalOutbox::class);
    }
}
