<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetVehicleComplianceRecord extends Model
{
    protected $fillable = ['asset_id', 'kind', 'current_version_id'];

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function currentVersion(): BelongsTo { return $this->belongsTo(FleetVehicleComplianceVersion::class, 'current_version_id'); }
    public function versions(): HasMany { return $this->hasMany(FleetVehicleComplianceVersion::class, 'record_id'); }
}
