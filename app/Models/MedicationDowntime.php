<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class MedicationDowntime extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];

    public function doses(): HasMany
    {
        return $this->hasMany(MedicationDowntimeDose::class, 'downtime_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MedicationPaperEntry::class, 'downtime_id');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(MedicationDowntimeSheet::class, 'downtime_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resolutions(): HasManyThrough
    {
        return $this->hasManyThrough(MedicationDowntimeResolution::class, MedicationDowntimeDose::class, 'downtime_id', 'downtime_dose_id');
    }
}
